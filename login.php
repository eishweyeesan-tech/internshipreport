<?php
session_start();
require_once 'config/database.php';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter username and password.';
    } else {
        // First, check if this is an email address
        $is_email = filter_var($username, FILTER_VALIDATE_EMAIL);

        if ($is_email) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :val LIMIT 1");
            $stmt->execute([':val' => $username]);
            $user = $stmt->fetch();
        } else {
            // Check how many users share this username (roll number)
            $cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :val");
            $cnt_stmt->execute([':val' => $username]);
            $cnt = (int) $cnt_stmt->fetchColumn();

            if ($cnt > 1) {
                // Same roll number exists across multiple academic years
                $error = 'Multiple accounts found for this roll number. Please log in with your email address instead.';
                $user = null;
            } elseif ($cnt === 1) {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :val LIMIT 1");
                $stmt->execute([':val' => $username]);
                $user = $stmt->fetch();
            } else {
                $user = null;
            }
        }

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['is_first_login'] = (bool) $user['is_first_login'];

            // Update last_login_at timestamp (safe fallback if column doesn't exist yet)
            try {
                $login_update = $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
                $login_update->execute([$user['id']]);
            } catch (PDOException $e) {
                // Column not yet added — silently ignore until migration is run
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
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
        .gradient-text {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-slate-50 via-indigo-50/50 to-purple-50/50 font-inter antialiased min-h-screen">

    <!-- Background Elements -->
    <div class="fixed inset-0 overflow-hidden pointer-events-none">
        <div class="absolute top-20 left-10 w-72 h-72 bg-indigo-300/20 rounded-full blur-3xl"></div>
        <div class="absolute bottom-20 right-10 w-96 h-96 bg-purple-300/20 rounded-full blur-3xl"></div>
    </div>

    <div class="relative min-h-screen flex items-center justify-center p-6">
        <div class="w-full max-w-5xl">
            <div class="grid lg:grid-cols-2 gap-8 items-center">
                
                <!-- Left Side - Branding -->
                <div class="hidden lg:block space-y-8">
                    <a href="index.php" class="inline-flex items-center gap-3">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center shadow-lg shadow-indigo-500/30">
                            <span class="text-white text-xl">📋</span>
                        </div>
                        <span class="text-2xl font-extrabold text-slate-800 tracking-tight">InternReport</span>
                    </a>
                    
                    <h1 class="text-4xl font-black text-slate-800 leading-tight">
                        Welcome Back to
                        <span class="gradient-text"> InternReport</span>
                    </h1>
                    
                    <p class="text-lg text-slate-600 leading-relaxed">
                        Sign in to manage your internship reports, track progress, and evaluate performance.
                    </p>

                    <!-- Features List -->
                    <div class="space-y-4">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-xl bg-indigo-100 flex items-center justify-center text-indigo-600 text-xl">📝</div>
                            <div>
                                <p class="font-bold text-slate-800">Daily Log Tracking</p>
                                <p class="text-sm text-slate-500">Submit and review daily activities</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-xl bg-purple-100 flex items-center justify-center text-purple-600 text-xl">📊</div>
                            <div>
                                <p class="font-bold text-slate-800">Weekly Grading</p>
                                <p class="text-sm text-slate-500">Track your weekly performance</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-xl bg-emerald-100 flex items-center justify-center text-emerald-600 text-xl">📈</div>
                            <div>
                                <p class="font-bold text-slate-800">Real-time Analytics</p>
                                <p class="text-sm text-slate-500">Monitor your progress</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Side - Login Form -->
                <div class="bg-white/80 backdrop-blur-xl rounded-3xl shadow-2xl shadow-slate-200/50 border border-slate-200/60 p-8 lg:p-10">
                    <!-- Mobile Logo -->
                    <div class="lg:hidden flex items-center justify-center gap-3 mb-8">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center shadow-lg shadow-indigo-500/30">
                            <span class="text-white text-lg">📋</span>
                        </div>
                        <span class="text-xl font-extrabold text-slate-800 tracking-tight">InternReport</span>
                    </div>

                    <div class="text-center mb-8">
                        <h2 class="text-2xl font-black text-slate-800 mb-2">Sign In</h2>
                        <p class="text-sm text-slate-500">Enter your credentials to access your account</p>
                    </div>

                    <?php if ($error): ?>
                    <div class="bg-gradient-to-r from-red-50 to-red-100/50 border border-red-200/60 text-red-700 text-sm font-semibold px-4 py-3 rounded-xl flex items-center gap-3 mb-6">
                        <div class="w-6 h-6 rounded-lg bg-red-500 text-white flex items-center justify-center text-xs">❌</div>
                        <?= htmlspecialchars($error) ?>
                    </div>
                    <?php endif; ?>

                    <form method="POST" class="space-y-5">
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2">Email</label>
                            <input type="email" name="username" required placeholder="Enter your email address"
                                   class="w-full px-4 py-3 bg-gradient-to-br from-slate-50 to-white border border-slate-200 rounded-xl text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all duration-200 shadow-sm placeholder:text-slate-400">
                            <p class="text-xs text-slate-400 mt-1">Use your email to log in (roll numbers may repeat across years).</p>
                        </div>

                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2">Password</label>
                            <input type="password" name="password" required placeholder="Enter your password"
                                   class="w-full px-4 py-3 bg-gradient-to-br from-slate-50 to-white border border-slate-200 rounded-xl text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all duration-200 shadow-sm placeholder:text-slate-400">
                        </div>

                        <button type="submit"
                                class="w-full px-6 py-3.5 bg-gradient-to-r from-indigo-500 to-purple-600 hover:from-indigo-600 hover:to-purple-700 text-white font-bold text-sm rounded-xl shadow-lg shadow-indigo-500/30 transition-all duration-200 hover:scale-[1.02]">
                            Sign In →
                        </button>
                    </form>

                    <div class="mt-6 text-center">
                        <a href="index.php" class="text-sm font-medium text-slate-500 hover:text-indigo-600 transition-colors">
                            ← Back to Home
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

</body>
</html>
