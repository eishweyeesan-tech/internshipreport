<?php
session_start();

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Internship Report Management System | InternReport</title>
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
            background: linear-gradient(135deg, #0d9488 0%, #059669 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .hero-gradient {
            background: linear-gradient(135deg, #0f766e 0%, #047857 50%, #10b981 100%);
        }
        .card-hover:hover {
            transform: translateY(-8px);
        }
        .float-animation {
            animation: float 6s ease-in-out infinite;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }
        .pulse-slow {
            animation: pulse 4s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
    </style>
</head>
<body class="bg-white font-inter antialiased">

    <!-- ════ NAVIGATION ════ -->
    <nav class="fixed top-0 left-0 right-0 z-50 bg-white/90 backdrop-blur-xl border-b border-teal-100">
        <div class="max-w-7xl mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-teal-600 to-emerald-700 flex items-center justify-center shadow-lg shadow-teal-600/30">
                        <span class="text-white text-lg">📋</span>
                    </div>
                    <span class="text-xl font-extrabold text-slate-800 tracking-tight">InternReport</span>
                </div>
                <div class="flex items-center gap-4">
                    <a href="#features" class="text-sm font-medium text-slate-600 hover:text-teal-700 transition">Features</a>
                    <a href="#roles" class="text-sm font-medium text-slate-600 hover:text-teal-700 transition">Roles</a>
                    <a href="#about" class="text-sm font-medium text-slate-600 hover:text-teal-700 transition">About</a>
                    <a href="login.php" class="px-5 py-2.5 bg-gradient-to-r from-teal-600 to-emerald-700 hover:from-teal-700 hover:to-emerald-800 text-white text-sm font-bold rounded-xl shadow-lg shadow-teal-600/30 transition-all duration-200 cursor-pointer">
                        Sign In
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- ════ HERO SECTION ════ -->
    <section class="relative min-h-screen flex items-center justify-center overflow-hidden pt-20">
        <!-- Background Elements -->
        <div class="absolute inset-0 bg-gradient-to-br from-slate-50 via-teal-50/40 to-emerald-50/40"></div>
        <div class="absolute top-20 left-10 w-72 h-72 bg-teal-300/20 rounded-full blur-3xl"></div>
        <div class="absolute bottom-20 right-10 w-96 h-96 bg-emerald-300/20 rounded-full blur-3xl"></div>
        <div class="absolute top-40 right-20 w-48 h-48 bg-teal-200/20 rounded-full blur-3xl"></div>

        <div class="relative max-w-7xl mx-auto px-6 py-20">
            <div class="grid lg:grid-cols-2 gap-16 items-center">
                <!-- Left Content -->
                <div class="space-y-8">
                    <h1 class="text-5xl lg:text-6xl font-black text-slate-800 leading-tight">
                        Internship Report
                        <span class="gradient-text">Management System</span>
                    </h1>

                    <p class="text-xl font-bold text-teal-700">Manage internship reports, track student progress, and simplify evaluation in one centralized system.</p>

                    <p class="text-lg text-slate-600 leading-relaxed max-w-xl">
                        A centralized internship report management system for students, supervisors, and company instructors.
                    </p>

                    <div class="flex items-center gap-4">
                        <a href="login.php" class="px-8 py-4 bg-gradient-to-r from-teal-600 to-emerald-700 hover:from-teal-700 hover:to-emerald-800 text-white text-base font-bold rounded-2xl shadow-xl shadow-teal-600/30 transition-all duration-300 hover:scale-105 cursor-pointer">
                            Get Started
                        </a>
                        <a href="#features" class="px-8 py-4 bg-white border-2 border-slate-200 text-slate-700 text-base font-bold rounded-2xl hover:border-teal-300 hover:text-teal-700 transition-all duration-300">
                            Learn More
                        </a>
                    </div>

                    <!-- Value Highlights (non-numeric — no test/demo statistics) -->
                    <div class="flex flex-wrap items-center gap-x-8 gap-y-4 pt-4">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-emerald-100/80 flex items-center justify-center text-emerald-700 text-base">🔒</div>
                            <p class="text-sm font-semibold text-slate-600">Secure &amp; Private</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-teal-100/80 flex items-center justify-center text-teal-700 text-base">📈</div>
                            <p class="text-sm font-semibold text-slate-600">Real-time Progress Tracking</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-emerald-100/80 flex items-center justify-center text-emerald-700 text-base">🔔</div>
                            <p class="text-sm font-semibold text-slate-600">Automated Alerts</p>
                        </div>
                    </div>
                </div>

                <!-- Right Content - How InternReport Works -->
                <div class="relative hidden lg:block">
                    <div class="bg-white rounded-3xl border border-teal-100 shadow-2xl shadow-teal-900/10 p-8">
                        <div class="flex items-center gap-3 mb-8">
                            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-teal-600 to-emerald-700 flex items-center justify-center text-white text-lg shadow-lg shadow-teal-600/30">⚙️</div>
                            <div>
                                <p class="font-bold text-slate-800">How InternReport Works</p>
                                <p class="text-xs text-slate-400">A guided workflow from start to finish</p>
                            </div>
                        </div>
                        <div class="space-y-0">
                            <div class="flex items-center gap-4">
                                <div class="w-10 h-10 rounded-xl bg-teal-100 text-teal-700 flex items-center justify-center text-lg shrink-0">🎓</div>
                                <div>
                                    <p class="text-sm font-bold text-slate-700">Student</p>
                                    <p class="text-xs text-slate-400">Submits work throughout the internship</p>
                                </div>
                            </div>
                            <div class="ml-5 h-6 flex items-center text-slate-300 text-sm">↓</div>
                            <div class="flex items-center gap-4">
                                <div class="w-10 h-10 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center text-lg shrink-0">📝</div>
                                <div>
                                    <p class="text-sm font-bold text-slate-700">Daily Logs</p>
                                    <p class="text-xs text-slate-400">Records tasks, hours, and skills learned</p>
                                </div>
                            </div>
                            <div class="ml-5 h-6 flex items-center text-slate-300 text-sm">↓</div>
                            <div class="flex items-center gap-4">
                                <div class="w-10 h-10 rounded-xl bg-teal-100 text-teal-700 flex items-center justify-center text-lg shrink-0">📊</div>
                                <div>
                                    <p class="text-sm font-bold text-slate-700">Weekly Reflection</p>
                                    <p class="text-xs text-slate-400">Documents learning and achievements</p>
                                </div>
                            </div>
                            <div class="ml-5 h-6 flex items-center text-slate-300 text-sm">↓</div>
                            <div class="flex items-center gap-4">
                                <div class="w-10 h-10 rounded-xl bg-teal-100 text-teal-700 flex items-center justify-center text-lg shrink-0">👨‍🏫</div>
                                <div>
                                    <p class="text-sm font-bold text-slate-700">Instructor Review</p>
                                    <p class="text-xs text-slate-400">Reviews and approves submissions</p>
                                </div>
                            </div>
                            <div class="ml-5 h-6 flex items-center text-slate-300 text-sm">↓</div>
                            <div class="flex items-center gap-4">
                                <div class="w-10 h-10 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center text-lg shrink-0">🏆</div>
                                <div>
                                    <p class="text-sm font-bold text-slate-700">Supervisor Grading</p>
                                    <p class="text-xs text-slate-400">Assigns weekly grades with feedback</p>
                                </div>
                            </div>
                            <div class="ml-5 h-6 flex items-center text-slate-300 text-sm">↓</div>
                            <div class="flex items-center gap-4">
                                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-teal-600 to-emerald-700 text-white flex items-center justify-center text-lg shrink-0 shadow-lg shadow-teal-600/30">🎉</div>
                                <div>
                                    <p class="text-sm font-bold text-slate-700">Internship Complete</p>
                                    <p class="text-xs text-slate-400">A complete record of the journey</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ════ FEATURES SECTION ════ -->
    <section id="features" class="py-24 bg-white">
        <div class="max-w-7xl mx-auto px-6">
            <div class="text-center mb-16">
                <span class="inline-flex items-center gap-2 px-4 py-2 bg-teal-50 border border-teal-200 rounded-full mb-4">
                    <span class="text-xs font-bold text-teal-700">Features</span>
                </span>
                <h2 class="text-4xl font-black text-slate-800 mb-4">Everything You Need</h2>
                <p class="text-lg text-slate-600 max-w-2xl mx-auto">
                    A complete suite of tools to manage internship reports, track attendance, and evaluate student performance.
                </p>
            </div>

            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
                <!-- Feature 1 -->
                <div class="group bg-gradient-to-br from-slate-50 to-white border border-slate-200/60 rounded-3xl p-8 card-hover transition-all duration-300 hover:shadow-xl hover:border-teal-200">
                    <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-teal-600 to-teal-700 flex items-center justify-center text-2xl text-white shadow-lg shadow-teal-600/30 mb-6 group-hover:scale-110 transition-transform duration-300">📝</div>
                    <h3 class="text-xl font-bold text-slate-800 mb-3">Daily Log Tracking</h3>
                    <p class="text-sm text-slate-600 leading-relaxed">
                        Students can submit daily logs with tasks performed, tools used, and skills learned. Supervisors can review in real-time.
                    </p>
                </div>

                <!-- Feature 2 -->
                <div class="group bg-gradient-to-br from-slate-50 to-white border border-slate-200/60 rounded-3xl p-8 card-hover transition-all duration-300 hover:shadow-xl hover:border-emerald-200">
                    <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-emerald-600 to-emerald-700 flex items-center justify-center text-2xl text-white shadow-lg shadow-emerald-600/30 mb-6 group-hover:scale-110 transition-transform duration-300">📊</div>
                    <h3 class="text-xl font-bold text-slate-800 mb-3">Weekly Reflections</h3>
                    <p class="text-sm text-slate-600 leading-relaxed">
                        Structured weekly reflection forms help students document their learning journey and achievements.
                    </p>
                </div>

                <!-- Feature 3 -->
                <div class="group bg-gradient-to-br from-slate-50 to-white border border-slate-200/60 rounded-3xl p-8 card-hover transition-all duration-300 hover:shadow-xl hover:border-teal-200">
                    <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-teal-600 to-emerald-600 flex items-center justify-center text-2xl text-white shadow-lg shadow-teal-600/30 mb-6 group-hover:scale-110 transition-transform duration-300">👨‍🏫</div>
                    <h3 class="text-xl font-bold text-slate-800 mb-3">Digital Instructor Evaluation</h3>
                    <p class="text-sm text-slate-600 leading-relaxed">
                        Company instructors evaluate student performance through a secure shared link — no account or login required.
                    </p>
                </div>

                <!-- Feature 4 -->
                <div class="group bg-gradient-to-br from-slate-50 to-white border border-slate-200/60 rounded-3xl p-8 card-hover transition-all duration-300 hover:shadow-xl hover:border-amber-200">
                    <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-amber-500 to-amber-600 flex items-center justify-center text-2xl text-white shadow-lg shadow-amber-500/30 mb-6 group-hover:scale-110 transition-transform duration-300">🎓</div>
                    <h3 class="text-xl font-bold text-slate-800 mb-3">University Grading</h3>
                    <p class="text-sm text-slate-600 leading-relaxed">
                        University supervisors assign weekly grades (A-F) with detailed comments on student performance.
                    </p>
                </div>

                <!-- Feature 5 -->
                <div class="group bg-gradient-to-br from-slate-50 to-white border border-slate-200/60 rounded-3xl p-8 card-hover transition-all duration-300 hover:shadow-xl hover:border-red-200">
                    <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-red-500 to-red-600 flex items-center justify-center text-2xl text-white shadow-lg shadow-red-500/30 mb-6 group-hover:scale-110 transition-transform duration-300">⚠️</div>
                    <h3 class="text-xl font-bold text-slate-800 mb-3">Progress Alerts</h3>
                    <p class="text-sm text-slate-600 leading-relaxed">
                        Automatic email alerts notify supervisors when students fall behind schedule on their daily logs.
                    </p>
                </div>

                <!-- Feature 6 -->
                <div class="group bg-gradient-to-br from-slate-50 to-white border border-slate-200/60 rounded-3xl p-8 card-hover transition-all duration-300 hover:shadow-xl hover:border-teal-200">
                    <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-teal-600 to-emerald-600 flex items-center justify-center text-2xl text-white shadow-lg shadow-teal-600/30 mb-6 group-hover:scale-110 transition-transform duration-300">📈</div>
                    <h3 class="text-xl font-bold text-slate-800 mb-3">Analytics Dashboard</h3>
                    <p class="text-sm text-slate-600 leading-relaxed">
                        Comprehensive dashboards with real-time statistics, attendance tracking, and performance metrics.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- ════ ROLES SECTION ════ -->
    <section id="roles" class="py-24 bg-gradient-to-br from-slate-50 via-teal-50/30 to-emerald-50/30">
        <div class="max-w-7xl mx-auto px-6">
            <div class="text-center mb-16">
                <span class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-50 border border-emerald-200 rounded-full mb-4">
                    <span class="text-xs font-bold text-emerald-700">For Everyone</span>
                </span>
                <h2 class="text-4xl font-black text-slate-800 mb-4">Built for All Roles</h2>
                <p class="text-lg text-slate-600 max-w-2xl mx-auto">
                    Three authenticated roles — Student, Supervisor, and Administrator — plus a company Instructor who reviews through a secure link.
                </p>
            </div>

            <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-8">
                <!-- Student Card -->
                <div class="bg-white rounded-3xl shadow-xl shadow-slate-200/50 overflow-hidden card-hover transition-all duration-300">
                    <div class="h-2 bg-gradient-to-r from-teal-600 to-teal-700"></div>
                    <div class="p-8">
                        <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-teal-600 to-teal-700 flex items-center justify-center text-3xl text-white shadow-lg shadow-teal-600/30 mb-6">🎓</div>
                        <h3 class="text-2xl font-bold text-slate-800 mb-3">Students</h3>
                        <p class="text-sm text-slate-600 mb-6 leading-relaxed">
                            Track your internship journey, submit daily logs, write weekly reflections, and monitor your progress.
                        </p>
                        <ul class="space-y-3 mb-8">
                            <li class="flex items-center gap-3 text-sm text-slate-600">
                                <span class="w-5 h-5 rounded-full bg-teal-100 text-teal-700 flex items-center justify-center text-xs">✓</span>
                                Daily log submissions
                            </li>
                            <li class="flex items-center gap-3 text-sm text-slate-600">
                                <span class="w-5 h-5 rounded-full bg-teal-100 text-teal-700 flex items-center justify-center text-xs">✓</span>
                                Weekly reflections
                            </li>
                            <li class="flex items-center gap-3 text-sm text-slate-600">
                                <span class="w-5 h-5 rounded-full bg-teal-100 text-teal-700 flex items-center justify-center text-xs">✓</span>
                                Progress tracking
                            </li>
                        </ul>
                        <a href="login.php" class="block w-full text-center px-6 py-3 bg-gradient-to-r from-teal-600 to-teal-700 hover:from-teal-700 hover:to-teal-800 text-white font-bold rounded-xl shadow-lg shadow-teal-600/30 transition-all duration-200 cursor-pointer">
                            Student Login →
                        </a>
                    </div>
                </div>

                <!-- Supervisor Card -->
                <div class="bg-white rounded-3xl shadow-xl shadow-slate-200/50 overflow-hidden card-hover transition-all duration-300">
                    <div class="h-2 bg-gradient-to-r from-emerald-600 to-emerald-700"></div>
                    <div class="p-8">
                        <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-emerald-600 to-emerald-700 flex items-center justify-center text-3xl text-white shadow-lg shadow-emerald-600/30 mb-6">👨‍💼</div>
                        <h3 class="text-2xl font-bold text-slate-800 mb-3">Supervisors</h3>
                        <p class="text-sm text-slate-600 mb-6 leading-relaxed">
                            Monitor student progress, assign weekly grades, and receive alerts when students need attention.
                        </p>
                        <ul class="space-y-3 mb-8">
                            <li class="flex items-center gap-3 text-sm text-slate-600">
                                <span class="w-5 h-5 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center text-xs">✓</span>
                                Student oversight
                            </li>
                            <li class="flex items-center gap-3 text-sm text-slate-600">
                                <span class="w-5 h-5 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center text-xs">✓</span>
                                Weekly grading
                            </li>
                            <li class="flex items-center gap-3 text-sm text-slate-600">
                                <span class="w-5 h-5 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center text-xs">✓</span>
                                Email alerts
                            </li>
                        </ul>
                        <a href="login.php" class="block w-full text-center px-6 py-3 bg-gradient-to-r from-emerald-600 to-emerald-700 hover:from-emerald-700 hover:to-emerald-800 text-white font-bold rounded-xl shadow-lg shadow-emerald-600/30 transition-all duration-200 cursor-pointer">
                            Supervisor Login →
                        </a>
                    </div>
                </div>

                <!-- Instructor Card (external reviewer — no login) -->
                <div class="bg-white rounded-3xl shadow-xl shadow-slate-200/50 overflow-hidden card-hover transition-all duration-300">
                    <div class="h-2 bg-gradient-to-r from-amber-500 to-amber-600"></div>
                    <div class="p-8">
                        <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-amber-500 to-amber-600 flex items-center justify-center text-3xl text-white shadow-lg shadow-amber-500/30 mb-6">👨‍🏫</div>
                        <h3 class="text-2xl font-bold text-slate-800 mb-1">Instructors</h3>
                        <p class="text-xs font-bold text-amber-600 uppercase tracking-wider mb-4">Company Reviewers</p>
                        <p class="text-sm text-slate-600 mb-6 leading-relaxed">
                            Review student internship reports through a secure shared link. No account or login is required.
                        </p>
                        <ul class="space-y-3">
                            <li class="flex items-center gap-3 text-sm text-slate-600">
                                <span class="w-5 h-5 rounded-full bg-amber-100 text-amber-600 flex items-center justify-center text-xs">✓</span>
                                Review student reports
                            </li>
                            <li class="flex items-center gap-3 text-sm text-slate-600">
                                <span class="w-5 h-5 rounded-full bg-amber-100 text-amber-600 flex items-center justify-center text-xs">✓</span>
                                Provide feedback
                            </li>
                            <li class="flex items-center gap-3 text-sm text-slate-600">
                                <span class="w-5 h-5 rounded-full bg-amber-100 text-amber-600 flex items-center justify-center text-xs">✓</span>
                                Evaluate performance
                            </li>
                            <li class="flex items-center gap-3 text-sm text-slate-600">
                                <span class="w-5 h-5 rounded-full bg-amber-100 text-amber-600 flex items-center justify-center text-xs">✓</span>
                                Approve / Reject submissions
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Admin Card -->
                <div class="bg-white rounded-3xl shadow-xl shadow-slate-200/50 overflow-hidden card-hover transition-all duration-300">
                    <div class="h-2 bg-gradient-to-r from-teal-700 to-emerald-700"></div>
                    <div class="p-8">
                        <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-teal-700 to-emerald-700 flex items-center justify-center text-3xl text-white shadow-lg shadow-teal-700/30 mb-6">⚙️</div>
                        <h3 class="text-2xl font-bold text-slate-800 mb-3">Administrators</h3>
                        <p class="text-sm text-slate-600 mb-6 leading-relaxed">
                            Full system control, user management, and comprehensive reporting capabilities.
                        </p>
                        <ul class="space-y-3 mb-8">
                            <li class="flex items-center gap-3 text-sm text-slate-600">
                                <span class="w-5 h-5 rounded-full bg-teal-100 text-teal-700 flex items-center justify-center text-xs">✓</span>
                                User management
                            </li>
                            <li class="flex items-center gap-3 text-sm text-slate-600">
                                <span class="w-5 h-5 rounded-full bg-teal-100 text-teal-700 flex items-center justify-center text-xs">✓</span>
                                System configuration
                            </li>
                            <li class="flex items-center gap-3 text-sm text-slate-600">
                                <span class="w-5 h-5 rounded-full bg-teal-100 text-teal-700 flex items-center justify-center text-xs">✓</span>
                                Analytics & reports
                            </li>
                        </ul>
                        <a href="login.php" class="block w-full text-center px-6 py-3 bg-gradient-to-r from-teal-700 to-emerald-700 hover:from-teal-800 hover:to-emerald-800 text-white font-bold rounded-xl shadow-lg shadow-teal-700/30 transition-all duration-200 cursor-pointer">
                            Admin Login →
                        </a>
                    </div>
                </div>
            </div>

            <!-- Instructor Access Note -->
            <div class="mt-12 bg-white rounded-2xl border border-amber-200/60 shadow-sm p-6 flex items-start gap-4 max-w-3xl mx-auto">
                <div class="w-10 h-10 rounded-xl bg-amber-100 flex items-center justify-center text-amber-600 text-lg shrink-0">🔗</div>
                <div>
                    <p class="text-sm font-bold text-slate-800 mb-1">How Company Instructors Access the System</p>
                    <p class="text-sm text-slate-600 leading-relaxed">
                        Students can share a secure evaluation link with their company instructor. The instructor opens the link directly in a browser to review reports and provide feedback — no account or login required.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- ════ ABOUT SECTION ════ -->
    <section id="about" class="py-24 bg-white">
        <div class="max-w-7xl mx-auto px-6">
            <div class="grid lg:grid-cols-2 gap-16 items-center">
                <div class="space-y-6">
                    <span class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-50 border border-emerald-200 rounded-full">
                        <span class="text-xs font-bold text-emerald-700">About Us</span>
                    </span>
                    <h2 class="text-4xl font-black text-slate-800 leading-tight">
                        Simplifying Internship Report Management
                    </h2>
                    <p class="text-lg text-slate-600 leading-relaxed">
                        InternReport is an Internship Report Management System that brings daily report submission, weekly reports and reflections, student progress tracking, supervisor review and grading, instructor feedback through a secure shared link, notifications, and attendance tracking into one centralized platform.
                    </p>
                    <div class="grid grid-cols-2 gap-6 pt-4">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-xl bg-teal-100 flex items-center justify-center text-teal-700 text-xl">🔒</div>
                            <div>
                                <p class="font-bold text-slate-800">Secure</p>
                                <p class="text-xs text-slate-500">Data protection</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-xl bg-emerald-100 flex items-center justify-center text-emerald-700 text-xl">⚡</div>
                            <div>
                                <p class="font-bold text-slate-800">Fast</p>
                                <p class="text-xs text-slate-500">Quick submissions</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-xl bg-teal-100 flex items-center justify-center text-teal-700 text-xl">📱</div>
                            <div>
                                <p class="font-bold text-slate-800">Responsive</p>
                                <p class="text-xs text-slate-500">Works everywhere</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-xl bg-emerald-100 flex items-center justify-center text-emerald-700 text-xl">🔔</div>
                            <div>
                                <p class="font-bold text-slate-800">Notifications</p>
                                <p class="text-xs text-slate-500">Stay updated</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="relative">
                    <div class="bg-gradient-to-br from-teal-700 to-emerald-800 rounded-3xl p-10 text-white shadow-2xl shadow-teal-700/30">
                        <p class="text-xl font-black mb-2">One Platform, Three Connected Roles</p>
                        <p class="text-sm text-teal-100 mb-10">Students submit. Instructors review. Supervisors grade. Everything stays connected.</p>
                        <div class="text-center">
                            <div class="inline-block bg-white/10 backdrop-blur-sm rounded-2xl px-10 py-6 mb-4 w-full">
                                <div class="w-14 h-14 mx-auto rounded-2xl bg-white/20 flex items-center justify-center text-2xl mb-3">🎓</div>
                                <p class="font-bold text-base">Student</p>
                                <p class="text-xs text-teal-100 mt-1">Submits daily logs &amp; reflections</p>
                            </div>
                            <div class="text-teal-200 text-2xl my-1">↓</div>
                            <div class="flex items-center justify-center gap-4">
                                <div class="flex-1 bg-white/10 backdrop-blur-sm rounded-2xl px-4 py-6">
                                    <div class="w-14 h-14 mx-auto rounded-2xl bg-white/20 flex items-center justify-center text-2xl mb-3">👨‍🏫</div>
                                    <p class="font-bold text-base">Instructor</p>
                                    <p class="text-xs text-teal-100 mt-1">Reviews &amp; approves</p>
                                </div>
                                <div class="flex-1 bg-white/10 backdrop-blur-sm rounded-2xl px-4 py-6">
                                    <div class="w-14 h-14 mx-auto rounded-2xl bg-white/20 flex items-center justify-center text-2xl mb-3">🏆</div>
                                    <p class="font-bold text-base">Supervisor</p>
                                    <p class="text-xs text-teal-100 mt-1">Grades &amp; feedback</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ════ CTA SECTION ════ -->
    <section class="py-24 bg-gradient-to-r from-teal-800 via-teal-700 to-emerald-800 relative overflow-hidden">
        <div class="absolute inset-0 bg-[url('data:image/svg+xml,%3Csvg width=\"60\" height=\"60\" viewBox=\"0 0 60 60\" xmlns=\"http://www.w3.org/2000/svg\"%3E%3Cg fill=\"none\" fill-rule=\"evenodd\"%3E%3Cg fill=\"%23ffffff\" fill-opacity=\"0.05\"%3E%3Cpath d=\"M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z\"/%3E%3C/g%3E%3C/g%3E%3C/svg%3E')]"></div>
        <div class="relative max-w-4xl mx-auto px-6 text-center">
            <h2 class="text-4xl font-black text-white mb-6">Ready to Get Started?</h2>
            <p class="text-lg text-teal-100 mb-8 max-w-2xl mx-auto">
                Sign in to submit daily logs, track progress, and manage evaluations throughout your internship.
            </p>
            <a href="login.php" class="inline-flex items-center gap-2 px-8 py-4 bg-white text-teal-800 text-base font-bold rounded-2xl shadow-xl hover:shadow-2xl hover:scale-105 transition-all duration-300 cursor-pointer">
                Sign In Now →
            </a>
        </div>
    </section>

    <!-- ════ FOOTER ════ -->
    <footer class="bg-slate-900 text-slate-400 py-16">
        <div class="max-w-7xl mx-auto px-6">
            <div class="grid md:grid-cols-4 gap-12 mb-12">
                <div class="space-y-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center shadow-lg shadow-indigo-500/30">
                            <span class="text-white text-lg">📋</span>
                        </div>
                        <span class="text-xl font-extrabold text-white tracking-tight">InternReport</span>
                    </div>
                    <p class="text-sm leading-relaxed">
                        A centralized internship report management system for students, supervisors, instructors, and administrators.
                    </p>
                </div>
                <div>
                    <h4 class="text-white font-bold mb-4">Quick Links</h4>
                    <ul class="space-y-2">
                        <li><a href="#features" class="text-sm hover:text-white transition">Features</a></li>
                        <li><a href="#roles" class="text-sm hover:text-white transition">Roles</a></li>
                        <li><a href="#about" class="text-sm hover:text-white transition">About</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="text-white font-bold mb-4">Login</h4>
                    <ul class="space-y-2">
                        <li><a href="login.php" class="text-sm hover:text-white transition">Student Login</a></li>
                        <li><a href="login.php" class="text-sm hover:text-white transition">Supervisor Login</a></li>
                        <li><a href="login.php" class="text-sm hover:text-white transition">Admin Login</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="text-white font-bold mb-4">Contact</h4>
                    <ul class="space-y-2">
                        <li class="text-sm">📧 support@internreport.com</li>
                    </ul>
                </div>
            </div>
            <div class="border-t border-slate-800 pt-8 text-center">
                <p class="text-sm">
                    © <?= date('Y') ?> InternReport. All rights reserved. Built with ❤️ for education.
                </p>
            </div>
        </div>
    </footer>

</body>
</html>
