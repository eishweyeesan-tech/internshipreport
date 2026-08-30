<?php
session_start();
require_once 'config/db.php';
require_once __DIR__ . '/includes/academic_year_helper.php';

// Redirect to dashboard if already logged in
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    switch ($_SESSION['role']) {
        case 'admin':
            header('Location: admin/admin-dashboard.php');
            break;
        case 'student':
            header('Location: student/student-dashboard.php');
            break;
        case 'supervisor':
            header('Location: supervisor/supervisor-dashboard.php');
            break;
        default:
            header('Location: login.php');
    }
    exit;
}

$db = $mysqli ?? $conn;
ensure_academic_years_table($db);
$current_active_year = get_active_academic_year_label($db, '2025-2026');

$error = '';
if (isset($_GET['error']) && $_GET['error'] === 'inactive') {
    $error = 'သင်၏ Supervisor အကောင့်မှာ Inactive ဖြစ်နေပါသဖြင့် Login ဝင်ရောက်ခွင့် မရှိပါ။ ကျေးဇူးပြု၍ Admin ထံ ဆက်သွယ်ပါ။';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please enter your email and password.';
    } else {
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result();
        $user = $res ? $res->fetch_assoc() : null;

        if ($user && password_verify($password, $user['password'])) {
            $user_status = trim((string) ($user['status'] ?? 'Active'));
            $user_academic_year = trim((string) ($user['academic_year'] ?? ''));

            // Restrict student access if status != Active OR academic_year != current active year
            if ($user['role'] === 'student') {
                $is_archived_status = (strtolower($user_status) !== 'active');
                $is_past_academic_year = ($user_academic_year !== '' && $user_academic_year !== $current_active_year);

                if ($is_archived_status || $is_past_academic_year) {
                    $error = 'သင်၏ အကောင့်မှာ ပညာသင်နှစ် ပြီးဆုံး၍ Archive လုပ်ထားပြီးဖြစ်သောကြောင့် Login ဝင်ရောက်ခွင့် မရှိတော့ပါ။';
                }
            }

            // Restrict supervisor access if account status is Inactive
            if ($user['role'] === 'supervisor') {
                if (strcasecmp($user_status, 'Inactive') === 0) {
                    $error = 'သင်၏ Supervisor အကောင့်မှာ Inactive ဖြစ်နေပါသဖြင့် Login ဝင်ရောက်ခွင့် မရှိပါ။ ကျေးဇူးပြု၍ Admin ထံ ဆက်သွယ်ပါ။';
                }
            }

            if (empty($error)) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['is_first_login'] = (bool) $user['is_first_login'];

                // Update last_login_at timestamp
                try {
                    $login_update = $db->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
                    $uid = (int) $user['id'];
                    $login_update->bind_param("i", $uid);
                    $login_update->execute();
                } catch (Throwable $e) {
                    // Silently ignore if error occurs
                }

                if ($user['is_first_login'] == 1) {
                    header('Location: change-password.php');
                    exit;
                }

                switch ($user['role']) {
                    case 'admin':
                        header('Location: admin/admin-dashboard.php');
                        break;
                    case 'student':
                        header('Location: student/student-dashboard.php');
                        break;
                    case 'supervisor':
                        header('Location: supervisor/supervisor-dashboard.php');
                        break;
                    default:
                        header('Location: login.php');
                }
                exit;
            }
        } else {
            $error = 'Invalid email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — InternReport University Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'sans': ['"Plus Jakarta Sans"', 'sans-serif'],
                    },
                }
            }
        }
    </script>
    <style>
        .univ-gradient-text {
            background: linear-gradient(135deg, #0d9488 0%, #0284c7 50%, #4338ca 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .grid-pattern {
            background-size: 28px 28px;
            background-image: 
                linear-gradient(to right, rgba(15, 23, 42, 0.035) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(15, 23, 42, 0.035) 1px, transparent 1px);
        }

        .spotlight-bg {
            background-image: 
                radial-gradient(at 0% 0%, rgba(13, 148, 136, 0.08) 0px, transparent 50%),
                radial-gradient(at 100% 0%, rgba(37, 99, 235, 0.08) 0px, transparent 50%),
                radial-gradient(at 50% 100%, rgba(124, 58, 237, 0.05) 0px, transparent 50%);
        }

        @keyframes shake-soft {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-4px); }
            40%, 80% { transform: translateX(4px); }
        }

        .animate-shake {
            animation: shake-soft 0.4s ease-in-out;
        }

        .fade-in-up {
            animation: fadeInUp 0.55s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(14px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>

<body class="bg-[#f8fafc] grid-pattern spotlight-bg font-sans antialiased min-h-screen text-slate-900 selection:bg-teal-600 selection:text-white flex flex-col justify-between">

    <!-- ════ TOP MINIMAL NAV ════ -->
    <header class="max-w-6xl w-full mx-auto px-6 py-5 flex items-center justify-between">
        <a href="index.php" class="flex items-center gap-3 group">
            <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-slate-900 via-teal-900 to-indigo-950 text-teal-400 flex items-center justify-center text-base shadow-sm border border-slate-800/40 group-hover:scale-105 transition-transform duration-200">
                <i class="fa-solid fa-graduation-cap"></i>
            </div>
            <div class="flex flex-col">
                <span class="text-sm font-black text-slate-900 tracking-tight leading-none group-hover:text-teal-700 transition-colors">InternReport</span>
                <span class="text-[10px] font-bold text-teal-700 tracking-wider uppercase mt-0.5">University Portal</span>
            </div>
        </a>

        <div class="flex items-center gap-2 px-3 py-1.5 bg-white/90 border border-slate-200/90 rounded-full text-xs font-bold text-slate-700 shadow-2xs">
            <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
            <span><?= htmlspecialchars($current_active_year) ?> Academic Year</span>
        </div>
    </header>

    <!-- ════ MAIN AUTH STAGE ════ -->
    <main class="flex-1 flex items-center justify-center p-5 sm:p-8">
        <div class="w-full max-w-5xl fade-in-up">
            <div class="grid lg:grid-cols-12 gap-8 lg:gap-12 items-center">

                <!-- Left Column: University Identity & Role Overviews -->
                <div class="hidden lg:block lg:col-span-6 space-y-6 pr-4">
                    <div class="space-y-3">
                        <h1 class="text-3xl font-black text-slate-900 tracking-tight leading-tight">
                            Sign In to <br>
                            <span class="univ-gradient-text">InternReport System</span>
                        </h1>

                        <p class="text-xs sm:text-sm text-slate-600 font-medium leading-relaxed">
                            Access your university workspace to record daily tasks, review submissions, and manage academic internship evaluations.
                        </p>
                    </div>

                    <!-- Clean Bento Role Highlights -->
                    <div class="space-y-3 pt-2">
                        <!-- Student Pill -->
                        <div class="p-3.5 bg-white/80 backdrop-blur-md rounded-2xl border border-blue-100 flex items-center gap-3.5 shadow-2xs hover:border-blue-300 transition-colors">
                            <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-sm font-bold shrink-0">
                                <i class="fa-solid fa-user-graduate"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-bold text-slate-900">Student Portal</p>
                                <p class="text-[11px] text-slate-500 truncate">Daily logs &amp; weekly reflections</p>
                            </div>
                        </div>

                        <!-- Supervisor Pill -->
                        <div class="p-3.5 bg-white/80 backdrop-blur-md rounded-2xl border border-teal-100 flex items-center gap-3.5 shadow-2xs hover:border-teal-300 transition-colors">
                            <div class="w-10 h-10 rounded-xl bg-teal-50 text-teal-600 flex items-center justify-center text-sm font-bold shrink-0">
                                <i class="fa-solid fa-chalkboard-user"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-bold text-slate-900">Faculty Supervisor</p>
                                <p class="text-[11px] text-slate-500 truncate">Student attendance monitoring &amp; A–F grading</p>
                            </div>
                        </div>

                        <!-- Admin Pill -->
                        <div class="p-3.5 bg-white/80 backdrop-blur-md rounded-2xl border border-slate-200 flex items-center gap-3.5 shadow-2xs hover:border-slate-300 transition-colors">
                            <div class="w-10 h-10 rounded-xl bg-slate-100 text-slate-800 flex items-center justify-center text-sm font-bold shrink-0">
                                <i class="fa-solid fa-shield-halved"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-bold text-slate-900">Department Admin</p>
                                <p class="text-[11px] text-slate-500 truncate">Academic batch governance, users &amp; company rosters</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Elevated Academic Login Form Card -->
                <div class="lg:col-span-6">
                    <div class="bg-white/95 backdrop-blur-xl rounded-3xl shadow-xl shadow-slate-900/5 border border-slate-200/90 p-8 sm:p-10 relative">
                        
                        <!-- Header -->
                        <div class="mb-6">
                            <h2 class="text-xl sm:text-2xl font-black text-slate-900 mb-1 tracking-tight">University Sign In</h2>
                            <p class="text-xs text-slate-500 font-medium">Enter your registered institutional credentials</p>
                        </div>

                        <!-- Error Message Banner -->
                        <?php if ($error): ?>
                            <div class="bg-red-50 border border-red-200 text-red-800 text-xs font-semibold px-4 py-3 rounded-xl flex items-start gap-2.5 mb-5 shadow-2xs animate-shake">
                                <i class="fa-solid fa-circle-exclamation text-red-500 text-sm shrink-0 mt-0.5"></i>
                                <div class="leading-relaxed flex-1"><?= htmlspecialchars($error) ?></div>
                            </div>
                        <?php endif; ?>

                        <!-- Login Form -->
                        <form method="POST" class="space-y-4" autocomplete="off">
                            <div>
                                <label class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1.5">Email Address</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                                        <i class="fa-regular fa-envelope text-xs"></i>
                                    </div>
                                    <input type="email" name="email" required placeholder="Enter your email" autocomplete="email"
                                        class="w-full pl-10 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm font-medium text-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-600 focus:bg-white transition-all shadow-2xs placeholder:text-slate-400">
                                </div>
                            </div>

                            <div>
                                <label class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1.5">Password</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                                        <i class="fa-solid fa-lock text-xs"></i>
                                    </div>
                                    <input type="password" id="passwordInput" name="password" required placeholder="Enter your password" autocomplete="current-password"
                                        class="w-full pl-10 pr-11 py-3 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm font-medium text-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-600 focus:bg-white transition-all shadow-2xs placeholder:text-slate-400">
                                    <button type="button" onclick="togglePasswordVisibility()" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700 p-1 focus:outline-none cursor-pointer transition-colors" aria-label="Toggle password visibility">
                                        <i id="eyeIcon" class="fa-regular fa-eye text-xs"></i>
                                    </button>
                                </div>
                            </div>

                            <button type="submit"
                                class="w-full mt-2 px-6 py-3.5 bg-slate-900 hover:bg-teal-600 active:scale-98 text-white font-bold text-xs sm:text-sm rounded-xl shadow-md shadow-slate-900/10 hover:shadow-teal-600/20 transition-all duration-200 cursor-pointer flex items-center justify-center gap-2 group">
                                <span>Sign In</span>
                                <i class="fa-solid fa-arrow-right text-xs group-hover:translate-x-1 transition-transform"></i>
                            </button>
                        </form>

                        <!-- Back to Home & Help -->
                        <div class="mt-6 pt-5 border-t border-slate-100 flex items-center justify-between text-[11px] font-bold text-slate-500">
                            <a href="index.php" class="hover:text-teal-700 transition-colors flex items-center gap-1.5 group">
                                <i class="fa-solid fa-arrow-left text-[10px] group-hover:-translate-x-0.5 transition-transform"></i>
                                <span>Back to Home</span>
                            </a>
                            <span class="text-slate-400 font-medium">InternReport System</span>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <!-- ════ FOOTER ════ -->
    <footer class="py-4 text-center text-xs text-slate-400 border-t border-slate-200/60 bg-white/60">
        © <?= date('Y') ?> InternReport. All rights reserved. University Internship Platform.
    </footer>

    <!-- Password visibility toggle script -->
    <script>
        function togglePasswordVisibility() {
            var pass = document.getElementById('passwordInput');
            var eye = document.getElementById('eyeIcon');
            if (!pass) return;
            if (pass.type === 'password') {
                pass.type = 'text';
                if (eye) {
                    eye.classList.remove('fa-eye');
                    eye.classList.add('fa-eye-slash');
                }
            } else {
                pass.type = 'password';
                if (eye) {
                    eye.classList.remove('fa-eye-slash');
                    eye.classList.add('fa-eye');
                }
            }
        }
    </script>
</body>

</html>