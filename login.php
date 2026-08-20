<?php
session_start();
require_once 'config/db.php';

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
        case 'instructor':
            header('Location: instructor/instructor-dashboard.php');
            break;
        default:
            header('Location: dashboard.php');
    }
    exit;
}

$error = '';
if (isset($_GET['error']) && $_GET['error'] === 'inactive') {
    $error = 'သင်၏ Supervisor အကောင့်မှာ Inactive ဖြစ်နေပါသဖြင့် Login ဝင်ရောက်ခွင့် မရှိပါ။ ကျေးဇူးပြု၍ Admin ထံ ဆက်သွယ်ပါ။';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter username and password.';
    } else {
        // First, check if this is an email address
        $is_email = filter_var($username, FILTER_VALIDATE_EMAIL);

        $db = $mysqli ?? $conn;
        if ($is_email) {
            $stmt = $db->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $res = $stmt->get_result();
            $user = $res ? $res->fetch_assoc() : null;
        } else {
            // Check how many users share this username (roll number)
            $cnt_stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $cnt_stmt->bind_param("s", $username);
            $cnt_stmt->execute();
            $res_cnt = $cnt_stmt->get_result();
            $cnt_row = $res_cnt ? $res_cnt->fetch_row() : [0];
            $cnt = (int) ($cnt_row[0] ?? 0);

            if ($cnt > 1) {
                // Same roll number exists across multiple academic years
                $error = 'Multiple accounts found for this roll number. Please log in with your email address instead.';
                $user = null;
            } elseif ($cnt === 1) {
                $stmt = $db->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $res = $stmt->get_result();
                $user = $res ? $res->fetch_assoc() : null;
            } else {
                $user = null;
            }
        }

        // Active Academic Year Standard
        require_once __DIR__ . '/includes/academic_year_helper.php';
        ensure_academic_years_table($db);
        $current_active_year = get_active_academic_year_label($db, '2023-2024');

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
                    case 'instructor':
                        header('Location: instructor/instructor-dashboard.php');
                        break;
                    default:
                        header('Location: dashboard.php');
                }
                exit;
            }
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login – InternReport</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'sans': ['"Plus Jakarta Sans"', 'Inter', 'sans-serif'],
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
        .gradient-text {
            background: linear-gradient(135deg, #0d9488 0%, #0284c7 50%, #2563eb 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        @keyframes shake-soft {

            0%,
            100% {
                transform: translateX(0);
            }

            20%,
            60% {
                transform: translateX(-4px);
            }

            40%,
            80% {
                transform: translateX(4px);
            }
        }

        .animate-shake {
            animation: shake-soft 0.4s ease-in-out;
        }
    </style>
</head>

<body class="bg-slate-50 font-sans antialiased min-h-screen text-slate-900 selection:bg-teal-500 selection:text-white flex flex-col justify-between">

    <!-- Main Container -->
    <main class="flex-1 flex items-center justify-center p-5 sm:p-8 lg:p-12">
        <div class="w-full max-w-6xl">
            <div class="grid lg:grid-cols-12 gap-8 lg:gap-12 items-center">

                <!-- Left Side: Clean, Simple, Normal Layout -->
                <div class="hidden lg:block lg:col-span-6 space-y-6 pr-4">
                    <!-- Brand Header -->
                    <a href="index.php" class="inline-flex items-center gap-3 group">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-teal-600 to-blue-700 flex items-center justify-center shadow-sm text-white text-xl">
                            <span>📋</span>
                        </div>
                        <div>
                            <span class="text-xl font-bold text-slate-900 tracking-tight block">InternReport</span>
                            <span class="text-xs text-slate-500 font-medium block">University Management System</span>
                        </div>
                    </a>

                    <!-- Title & Description -->
                    <div class="space-y-2">
                        <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight leading-tight">
                            Welcome Back to <span class="text-teal-700">InternReport</span>
                        </h1>
                        <p class="text-sm text-slate-600 leading-relaxed">
                            Sign in to manage internship reports, submit daily logs, track evaluation grades, and collaborate in real-time.
                        </p>
                    </div>

                    <!-- Clean, Natural Internship Image -->
                    <div class="rounded-2xl overflow-hidden border border-slate-200 shadow-sm bg-white">
                        <img src="assets/images/login_internship_visual.jpg" alt="University Internship Evaluation" class="w-full h-auto max-h-[250px] object-cover">
                    </div>

                    <!-- Clean Role Information List -->
                    <div class="space-y-2.5 pt-1">
                        <div class="flex items-center gap-3 text-sm text-slate-700">
                            <span class="w-7 h-7 rounded-lg bg-blue-50 text-blue-700 flex items-center justify-center text-xs font-bold shrink-0">🎓</span>
                            <span><strong class="font-semibold text-slate-800">Students:</strong> Submit daily logs and weekly reflections</span>
                        </div>
                        <div class="flex items-center gap-3 text-sm text-slate-700">
                            <span class="w-7 h-7 rounded-lg bg-purple-50 text-purple-700 flex items-center justify-center text-xs font-bold shrink-0">👨‍🏫</span>
                            <span><strong class="font-semibold text-slate-800">Supervisors:</strong> Review progress and assign weekly grades</span>
                        </div>
                        <div class="flex items-center gap-3 text-sm text-slate-700">
                            <span class="w-7 h-7 rounded-lg bg-teal-50 text-teal-700 flex items-center justify-center text-xs font-bold shrink-0">⚙️</span>
                            <span><strong class="font-semibold text-slate-800">Administrators:</strong> Manage users, companies, and academic years</span>
                        </div>
                    </div>
                </div>

                <!-- Right Side: Elevated Login Card (100% Preserved) -->
                <div class="lg:col-span-6">
                    <div class="bg-white/95 backdrop-blur-2xl rounded-3xl shadow-2xl shadow-slate-900/10 border border-slate-200/80 p-8 sm:p-10 relative">
                        <!-- Mobile Header -->
                        <div class="lg:hidden flex items-center justify-center gap-3 mb-6 text-center">
                            <div class="w-11 h-11 rounded-2xl bg-gradient-to-br from-teal-600 to-blue-700 flex items-center justify-center shadow-md shadow-teal-600/25">
                                <span class="text-white text-xl">📋</span>
                            </div>
                            <div class="text-left">
                                <span class="text-xl font-black text-slate-900 tracking-tight block">InternReport</span>
                                <span class="text-[0.625rem] font-extrabold uppercase tracking-widest text-teal-700 block">University Portal</span>
                            </div>
                        </div>

                        <div class="mb-7">
                            <h2 class="text-2xl sm:text-3xl font-black text-slate-900 mb-1.5 tracking-tight">Sign In to Your Account</h2>
                            <p class="text-sm text-slate-500 font-medium">Enter your university credentials to access the platform</p>
                        </div>

                        <!-- Error Message Banner -->
                        <?php if ($error): ?>
                            <div class="bg-red-50/95 border border-red-200 text-red-800 text-sm font-semibold px-4 py-3.5 rounded-2xl flex items-start gap-3 mb-6 shadow-sm animate-shake">
                                <span class="w-5 h-5 rounded-full bg-red-500 text-white flex items-center justify-center text-xs shrink-0 mt-0.5 font-bold">!</span>
                                <div class="leading-relaxed flex-1"><?= htmlspecialchars($error) ?></div>
                            </div>
                        <?php endif; ?>

                        <!-- Login Form -->
                        <form method="POST" class="space-y-5" autocomplete="off">
                            <div>
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">Email or Username</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                                        <i class="fa-regular fa-envelope text-slate-400"></i>
                                    </div>
                                    <input type="text" name="username" required placeholder="Enter your email or roll number" autocomplete="off"
                                        class="w-full pl-11 pr-4 py-3.5 bg-slate-50/80 border border-slate-200 rounded-2xl text-sm font-medium text-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-600 focus:bg-white transition-all duration-200 shadow-sm placeholder:text-slate-400">
                                </div>
                                <p class="text-xs text-slate-400 mt-1.5 flex items-center gap-1">
                                    <span>💡</span> Use your email to log in (roll numbers may repeat across years).
                                </p>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">Password</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                                        <i class="fa-solid fa-lock text-slate-400"></i>
                                    </div>
                                    <input type="password" id="passwordInput" name="password" required placeholder="••••••••" autocomplete="new-password"
                                        class="w-full pl-11 pr-12 py-3.5 bg-slate-50/80 border border-slate-200 rounded-2xl text-sm font-medium text-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-500/20 focus:border-teal-600 focus:bg-white transition-all duration-200 shadow-sm placeholder:text-slate-400">
                                    <button type="button" onclick="togglePasswordVisibility()" class="absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700 p-1.5 focus:outline-none cursor-pointer flex items-center justify-center transition-colors" aria-label="Toggle password visibility">
                                        <i id="eyeIcon" class="fa-regular fa-eye text-slate-400 hover:text-slate-700 text-sm transition-colors"></i>
                                    </button>
                                </div>
                            </div>

                            <button type="submit"
                                class="w-full px-6 py-4 bg-gradient-to-r from-teal-600 via-teal-700 to-blue-700 hover:from-teal-700 hover:to-blue-800 text-white font-bold text-sm rounded-2xl shadow-xl shadow-teal-600/25 hover:shadow-teal-600/40 hover:scale-[1.01] active:scale-[0.99] transition-all duration-200 cursor-pointer flex items-center justify-center gap-2">
                                <span>Sign In</span>
                                <span class="text-base">→</span>
                            </button>
                        </form>

                        <!-- Back to Home & Help Links -->
                        <div class="mt-8 pt-6 border-t border-slate-100 flex items-center justify-between text-xs font-semibold text-slate-500">
                            <a href="index.php" class="hover:text-teal-700 transition-colors flex items-center gap-1.5 group">
                                <span class="group-hover:-translate-x-0.5 transition-transform">←</span>
                                <span>Back to Home</span>
                            </a>
                            <span class="text-slate-400">Internship Report Management System</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer Copyright -->
    <footer class="py-4 text-center text-xs text-slate-400">
        © <?= date('Y') ?> InternReport. All rights reserved. University Internship Platform.
    </footer>

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