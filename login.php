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
    <title>Sign In — Polytechnic University (Faculty of Computing)</title>
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
        .gradient-text {
            background: linear-gradient(135deg, #0f766e 0%, #0284c7 50%, #1e40af 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        /* Ambient subtle animations */
        @keyframes float-slow {
            0%, 100% { transform: translate(0px, 0px); }
            50% { transform: translate(15px, -15px); }
        }

        .animate-float-slow {
            animation: float-slow 16s ease-in-out infinite;
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
            animation: fadeInUp 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(16px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>

<body class="bg-[#f8fafc] font-sans antialiased min-h-screen text-slate-900 selection:bg-teal-700 selection:text-white flex flex-col justify-between relative overflow-x-hidden">

    <!-- Ambient University Background Glows -->
    <div class="absolute top-0 left-0 right-0 h-96 bg-gradient-to-b from-teal-50/40 via-slate-50/20 to-transparent pointer-events-none -z-10"></div>
    <div class="absolute top-20 left-12 w-80 h-80 bg-teal-200/15 rounded-full blur-3xl animate-float-slow pointer-events-none -z-10"></div>
    <div class="absolute bottom-10 right-12 w-96 h-96 bg-blue-200/15 rounded-full blur-3xl animate-float-slow pointer-events-none -z-10"></div>

    <!-- ════ TOP MINIMAL UNIVERSITY NAV ════ -->
    <header class="max-w-6xl w-full mx-auto px-6 py-5 flex items-center justify-between z-10">
        <a href="index.php" class="flex items-center gap-3 group">
            <div class="w-10 h-10 rounded-2xl bg-gradient-to-br from-teal-700 via-teal-800 to-slate-900 text-white flex items-center justify-center text-base shadow-sm group-hover:scale-105 transition-transform duration-200">
                <i class="fa-solid fa-graduation-cap"></i>
            </div>
            <div class="flex flex-col">
                <span class="text-base font-black text-slate-900 tracking-tight leading-none group-hover:text-teal-700 transition-colors">InternReport</span>
                <span class="text-[10px] font-extrabold text-teal-700 tracking-wider uppercase mt-1">Polytechnic University</span>
            </div>
        </a>

        <div class="flex items-center gap-2 px-3.5 py-1.5 bg-white border border-slate-200/90 rounded-full text-xs font-bold text-slate-700 shadow-2xs">
            <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
            <span><?= htmlspecialchars($current_active_year) ?> Academic Year</span>
        </div>
    </header>

    <!-- ════ MAIN AUTH STAGE ════ -->
    <main class="flex-1 flex items-center justify-center p-5 sm:p-8 z-10">
        <div class="w-full max-w-5xl fade-in-up">
            <div class="grid lg:grid-cols-12 gap-8 lg:gap-14 items-center">

                <!-- Left Column: University Academic Identity -->
                <div class="hidden lg:block lg:col-span-6 space-y-6 pr-2">
                    
                    <!-- Institutional Badge -->
                    <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-white border border-teal-200/80 shadow-2xs">
                        <span class="w-2 h-2 rounded-full bg-teal-600"></span>
                        <span class="text-[11px] font-extrabold uppercase tracking-wider text-teal-800">
                            Faculty of Computing
                        </span>
                    </div>

                    <div class="space-y-3">
                        <h1 class="text-3xl sm:text-4xl font-black text-slate-900 tracking-tight leading-[1.2]">
                            Internship Report <br>
                            <span class="gradient-text">Management System</span>
                        </h1>

                        <p class="text-xs sm:text-sm text-slate-600 font-medium leading-relaxed max-w-md">
                            Official institutional system for undergraduate interns, university supervisors, and administrators to verify daily work logs, submit weekly reflections, and record academic evaluations.
                        </p>
                    </div>

                    <!-- Academic Integrity Highlights -->
                    <div class="space-y-2.5 pt-2">
                        <div class="flex items-center gap-3 p-3 rounded-xl bg-white border border-slate-200/80 shadow-2xs">
                            <div class="w-8 h-8 rounded-lg bg-teal-50 text-teal-700 flex items-center justify-center text-xs font-bold shrink-0">
                                <i class="fa-solid fa-clipboard-check"></i>
                            </div>
                            <span class="text-xs font-bold text-slate-800">Daily Task &amp; Working Hours Tracking</span>
                        </div>

                        <div class="flex items-center gap-3 p-3 rounded-xl bg-white border border-slate-200/80 shadow-2xs">
                            <div class="w-8 h-8 rounded-lg bg-blue-50 text-blue-700 flex items-center justify-center text-xs font-bold shrink-0">
                                <i class="fa-solid fa-building-user"></i>
                            </div>
                            <span class="text-xs font-bold text-slate-800">Company Instructor Secure Verification</span>
                        </div>

                        <div class="flex items-center gap-3 p-3 rounded-xl bg-white border border-slate-200/80 shadow-2xs">
                            <div class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-700 flex items-center justify-center text-xs font-bold shrink-0">
                                <i class="fa-solid fa-award"></i>
                            </div>
                            <span class="text-xs font-bold text-slate-800">Standardized A–F University Grading</span>
                        </div>
                    </div>

                </div>

                <!-- Right Column: University Login Form Card -->
                <div class="lg:col-span-6">
                    <div class="bg-white rounded-3xl shadow-xl shadow-slate-900/5 border border-slate-200/90 p-8 sm:p-10 relative">
                        
                        <!-- Header -->
                        <div class="mb-6">
                            <h2 class="text-2xl font-black text-slate-900 mb-1 tracking-tight">Account Sign In</h2>
                            <p class="text-xs text-slate-500 font-medium">Enter your credentials to continue</p>
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
                                    <input type="email" name="email" required placeholder="Enter your email address" autocomplete="off"
                                        class="w-full pl-10 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm font-medium text-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-600/20 focus:border-teal-700 focus:bg-white transition-all shadow-2xs placeholder:text-slate-400">
                                </div>
                            </div>

                            <div>
                                <label class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1.5">Password</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                                        <i class="fa-solid fa-lock text-xs"></i>
                                    </div>
                                    <input type="password" id="passwordInput" name="password" required placeholder="Enter password" autocomplete="new-password"
                                        class="w-full pl-10 pr-11 py-3 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm font-medium text-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-600/20 focus:border-teal-700 focus:bg-white transition-all shadow-2xs placeholder:text-slate-400">
                                    <button type="button" onclick="togglePasswordVisibility()" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700 p-1 focus:outline-none cursor-pointer transition-colors" aria-label="Toggle password visibility">
                                        <i id="eyeIcon" class="fa-regular fa-eye text-xs"></i>
                                    </button>
                                </div>
                            </div>

                            <button type="submit"
                                class="w-full mt-2 px-6 py-3.5 bg-gradient-to-r from-teal-700 via-teal-800 to-slate-900 hover:from-teal-800 hover:to-black text-white font-bold text-xs sm:text-sm rounded-xl shadow-lg shadow-teal-900/20 hover:shadow-teal-900/35 hover:scale-[1.01] active:scale-[0.98] transition-all duration-200 cursor-pointer flex items-center justify-center gap-2 group">
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
    <footer class="py-4 text-center text-xs text-slate-400 border-t border-slate-200/70 bg-white">
        © <?= date('Y') ?> Polytechnic University (Faculty of Computing). All rights reserved.
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