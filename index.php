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
        default:
            header('Location: login.php');
    }
    exit;
}

require_once __DIR__ . '/config/db.php';

$stat_students   = (int)($mysqli->query("SELECT COUNT(*) AS c FROM users WHERE role = 'student'")->fetch_assoc()['c'] ?? 0);
$stat_companies  = (int)($mysqli->query("SELECT COUNT(*) AS c FROM companies")->fetch_assoc()['c'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Internship Report Management System | InternReport</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
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

        .gradient-text-alt {
            background: linear-gradient(135deg, #0f766e 0%, #059669 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .hero-gradient {
            background: linear-gradient(135deg, #0f766e 0%, #047857 50%, #10b981 100%);
        }

        .card-hover {
            transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .card-hover:hover {
            transform: translateY(-8px) scale(1.01);
        }

        /* Floating background blobs */
        @keyframes float-slow {
            0%, 100% { transform: translate(0px, 0px) scale(1); }
            50% { transform: translate(20px, -25px) scale(1.08); }
        }

        @keyframes float-reverse {
            0%, 100% { transform: translate(0px, 0px) scale(1); }
            50% { transform: translate(-25px, 20px) scale(0.94); }
        }

        @keyframes pulse-subtle {
            0%, 100% { opacity: 0.25; transform: scale(1); }
            50% { opacity: 0.45; transform: scale(1.1); }
        }

        .animate-float-slow {
            animation: float-slow 10s ease-in-out infinite;
        }

        .animate-float-reverse {
            animation: float-reverse 12s ease-in-out infinite;
        }

        .animate-pulse-subtle {
            animation: pulse-subtle 8s ease-in-out infinite;
        }

        /* Scroll reveal animation system */
        .reveal-init {
            opacity: 0;
            transform: translateY(24px);
            transition: opacity 0.8s cubic-bezier(0.16, 1, 0.3, 1), transform 0.8s cubic-bezier(0.16, 1, 0.3, 1);
            will-change: opacity, transform;
        }

        .reveal-visible {
            opacity: 1;
            transform: translateY(0);
        }

        /* Nav link underline micro-animation */
        .nav-link-hover {
            position: relative;
        }

        .nav-link-hover::after {
            content: '';
            position: absolute;
            bottom: -3px;
            left: 50%;
            width: 0;
            height: 2px;
            background: linear-gradient(90deg, #0d9488, #2563eb);
            transition: all 0.25s ease-in-out;
            transform: translateX(-50%);
            border-radius: 9999px;
        }

        .nav-link-hover:hover::after {
            width: 100%;
        }

        /* Subtle page entrance */
        @keyframes page-fade {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        body {
            animation: page-fade 0.4s ease-out;
        }

        /* Seamless Horizontal Marquee for Features Section */
        @keyframes marquee-features {
            0% { transform: translate3d(0, 0, 0); }
            100% { transform: translate3d(-50%, 0, 0); }
        }

        .marquee-wrapper {
            overflow: hidden;
            width: 100%;
            position: relative;
            mask-image: linear-gradient(to right, transparent 0%, black 3%, black 97%, transparent 100%);
            -webkit-mask-image: linear-gradient(to right, transparent 0%, black 3%, black 97%, transparent 100%);
        }

        .marquee-track {
            display: flex;
            width: max-content;
            animation: marquee-features 38s linear infinite;
            will-change: transform;
        }

        .marquee-wrapper:hover .marquee-track {
            animation-play-state: paused;
        }
    </style>
</head>

<body class="bg-slate-50/50 font-sans antialiased text-slate-900 selection:bg-teal-500 selection:text-white">

    <!-- ════ NAVIGATION ════ -->
    <nav id="main-nav" class="fixed top-0 left-0 right-0 z-50 bg-white/80 backdrop-blur-xl border-b border-slate-200/80 transition-all duration-300">
        <div class="max-w-7xl mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <a href="#" class="flex items-center gap-3.5 group">
                    <div class="w-11 h-11 rounded-2xl bg-gradient-to-br from-teal-600 via-teal-700 to-blue-700 flex items-center justify-center shadow-lg shadow-teal-600/25 group-hover:scale-105 transition-transform duration-300">
                        <span class="text-white text-xl">📋</span>
                    </div>
                    <div>
                        <span class="text-xl font-black text-slate-900 tracking-tight block group-hover:text-teal-700 transition-colors duration-200">InternReport</span>
                    </div>
                </a>
                <div class="flex items-center gap-8">
                    <div class="hidden md:flex items-center gap-7">
                        <a href="#features" class="nav-link-hover text-sm font-bold text-slate-600 hover:text-slate-900 transition-colors duration-200">Features</a>
                        <a href="#how-it-works" class="nav-link-hover text-sm font-bold text-slate-600 hover:text-slate-900 transition-colors duration-200">How It Works</a>
                        <a href="#roles" class="nav-link-hover text-sm font-bold text-slate-600 hover:text-slate-900 transition-colors duration-200">Roles</a>
                        <a href="#about" class="nav-link-hover text-sm font-bold text-slate-600 hover:text-slate-900 transition-colors duration-200">About</a>
                    </div>
                    <a href="login.php" class="px-6 py-2.5 bg-gradient-to-r from-teal-600 via-teal-700 to-blue-700 hover:from-teal-700 hover:to-blue-800 text-white text-sm font-bold rounded-xl shadow-lg shadow-teal-600/25 hover:shadow-teal-600/40 hover:scale-[1.03] active:scale-[0.98] transition-all duration-200 cursor-pointer">
                        Sign In →
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- ════ HERO SECTION ════ -->
    <section class="relative min-h-[92vh] flex items-center justify-center overflow-hidden pt-28 pb-16">
        <!-- Sophisticated Ambient Background -->
        <div class="absolute inset-0 bg-gradient-to-b from-slate-50 via-teal-50/25 to-sky-50/30"></div>
        <div class="absolute top-24 left-12 w-80 h-80 bg-teal-300/20 rounded-full blur-3xl animate-float-slow pointer-events-none"></div>
        <div class="absolute bottom-16 right-12 w-[32rem] h-[32rem] bg-blue-300/20 rounded-full blur-3xl animate-float-reverse pointer-events-none"></div>
        <div class="absolute top-1/2 left-1/3 w-64 h-64 bg-indigo-200/20 rounded-full blur-3xl animate-pulse-subtle pointer-events-none"></div>

        <div class="relative max-w-7xl mx-auto px-6">
            <div class="grid lg:grid-cols-12 gap-12 lg:gap-14 items-center">
                <!-- Left Content -->
                <div class="lg:col-span-6 space-y-7 reveal-init">
                    <div class="inline-flex items-center gap-2.5 px-4 py-1.5 rounded-full bg-white/90 border border-teal-200/80 shadow-sm backdrop-blur-sm">
                        <span class="w-2.5 h-2.5 rounded-full bg-teal-500 animate-pulse"></span>
                        <span class="text-xs font-black uppercase tracking-wider text-teal-800">Academic & Industry Collaboration</span>
                    </div>

                    <h1 class="text-4xl sm:text-5xl lg:text-6xl font-black text-slate-900 leading-[1.15] tracking-tight">
                        Internship Report
                        <span class="gradient-text block mt-1">Management System</span>
                    </h1>

                    <p class="text-lg sm:text-xl font-bold text-teal-700 leading-snug">
                        Manage internship reports, track student progress, and simplify evaluation in one centralized system.
                    </p>

                    <p class="text-base sm:text-lg text-slate-600 leading-relaxed max-w-xl">
                        A centralized internship report management system for students, CU supervisors, and company instructors.
                    </p>

                    <!-- CTA Buttons -->
                    <div class="flex flex-wrap items-center gap-4 pt-1">
                        <a href="login.php" class="px-8 py-4 bg-gradient-to-r from-teal-600 via-teal-700 to-blue-700 hover:from-teal-700 hover:to-blue-800 text-white text-base font-bold rounded-2xl shadow-xl shadow-teal-600/30 hover:shadow-teal-600/50 transition-all duration-300 hover:scale-105 active:scale-[0.98] cursor-pointer inline-flex items-center gap-2">
                            <span>Get Started</span>
                            <span class="text-lg">→</span>
                        </a>
                        <a href="#features" class="px-8 py-4 bg-white/90 backdrop-blur-sm border-2 border-slate-200 text-slate-700 text-base font-bold rounded-2xl hover:border-teal-400 hover:text-teal-700 hover:bg-teal-50/40 hover:shadow-md transition-all duration-300">
                            Learn More
                        </a>
                    </div>

                    <!-- Floating Statistics Cards (Stacked Dynamic Counters) -->
                    <div class="grid grid-cols-3 gap-4 pt-4">
                        <!-- Stat 1: Students -->
                        <div class="flex flex-col items-start p-4 rounded-2xl bg-gradient-to-br from-white to-blue-50/60 border border-blue-200/80 shadow-md shadow-blue-900/5 hover:shadow-xl hover:border-blue-400 hover:-translate-y-1.5 transition-all duration-300 group">
                            <span class="text-xl mb-1 group-hover:scale-110 transition-transform">👨‍🎓</span>
                            <span class="text-2xl sm:text-3xl font-black text-slate-900" data-target-count="<?= $stat_students ?>" data-suffix="+"><?= $stat_students ?>+</span>
                            <span class="text-[0.6875rem] font-extrabold text-blue-700 uppercase tracking-wider mt-0.5">Students</span>
                        </div>

                        <!-- Stat 2: Companies -->
                        <div class="flex flex-col items-start p-4 rounded-2xl bg-gradient-to-br from-white to-teal-50/60 border border-teal-200/80 shadow-md shadow-teal-900/5 hover:shadow-xl hover:border-teal-400 hover:-translate-y-1.5 transition-all duration-300 group">
                            <span class="text-xl mb-1 group-hover:scale-110 transition-transform">🏢</span>
                            <span class="text-2xl sm:text-3xl font-black text-slate-900" data-target-count="<?= $stat_companies ?>" data-suffix="+"><?= $stat_companies ?>+</span>
                            <span class="text-[0.6875rem] font-extrabold text-teal-700 uppercase tracking-wider mt-0.5">Companies</span>
                        </div>

                        <!-- Stat 3: 100% Digital -->
                        <div class="flex flex-col items-start p-4 rounded-2xl bg-gradient-to-br from-white to-purple-50/60 border border-purple-200/80 shadow-md shadow-purple-900/5 hover:shadow-xl hover:border-purple-400 hover:-translate-y-1.5 transition-all duration-300 group">
                            <span class="text-xl mb-1 group-hover:scale-110 transition-transform">⚡</span>
                            <span class="text-2xl sm:text-3xl font-black text-slate-900">100%</span>
                            <span class="text-[0.6875rem] font-extrabold text-purple-700 uppercase tracking-wider mt-0.5">Digital</span>
                        </div>
                    </div>
                </div>

                <!-- Right Content - High Quality Internship Image Visual -->
                <div class="lg:col-span-6 relative reveal-init">
                    <div class="relative rounded-3xl p-3 bg-gradient-to-br from-white/90 via-teal-100/30 to-blue-100/40 border border-white shadow-2xl shadow-slate-900/15">
                        <div class="rounded-2xl overflow-hidden shadow-inner relative group">
                            <img src="assets/images/internship_hero_visual.jpg" alt="University Internship Tech Lab & Mentoring" class="w-full h-auto object-cover rounded-2xl group-hover:scale-105 transition-transform duration-700">
                            
                            <!-- Soft Gradient Vignette -->
                            <div class="absolute inset-0 bg-gradient-to-t from-slate-950/60 via-transparent to-transparent pointer-events-none"></div>

                            <!-- Bottom Image Caption Badge -->
                            <div class="absolute bottom-4 left-4 right-4 p-4 rounded-xl bg-white/90 backdrop-blur-md border border-white/80 shadow-lg flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-teal-600 to-emerald-600 flex items-center justify-center text-white text-lg shadow-sm shrink-0">🎓</div>
                                    <div>
                                        <p class="text-xs font-black text-slate-900">Real-time Student Evaluation</p>
                                        <p class="text-[0.6875rem] text-slate-500 font-medium">Daily Logs • Weekly Reports • Supervisor Grades</p>
                                    </div>
                                </div>
                                <span class="px-2.5 py-1 rounded-full bg-emerald-100 text-emerald-700 text-[0.625rem] font-black uppercase tracking-wider shrink-0">Active</span>
                            </div>
                        </div>

                        <!-- Floating Live UI Badge Top Left -->
                        <div class="absolute -top-5 -left-5 p-3.5 rounded-2xl bg-white/95 backdrop-blur-xl border border-slate-200/90 shadow-xl flex items-center gap-3 hover:scale-105 transition-transform duration-300">
                            <div class="w-9 h-9 rounded-xl bg-blue-100 text-blue-700 flex items-center justify-center text-base font-bold shrink-0">📝</div>
                            <div>
                                <p class="text-xs font-bold text-slate-900">Daily Log Submissions</p>
                                <p class="text-[0.625rem] text-emerald-600 font-extrabold">✓ Verified in Real-time</p>
                            </div>
                        </div>

                        <!-- Floating Live UI Badge Bottom Right -->
                        <div class="absolute -bottom-4 -right-4 p-3.5 rounded-2xl bg-white/95 backdrop-blur-xl border border-slate-200/90 shadow-xl flex items-center gap-3 hover:scale-105 transition-transform duration-300">
                            <div class="w-9 h-9 rounded-xl bg-purple-100 text-purple-700 flex items-center justify-center text-base font-bold shrink-0">🏆</div>
                            <div>
                                <p class="text-xs font-bold text-slate-900">Academic Grading</p>
                                <p class="text-[0.625rem] text-purple-700 font-extrabold">A-F Scale + Feedback</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ════ FEATURES SECTION ════ -->
    <section id="features" class="py-24 bg-white border-t border-slate-100">
        <div class="max-w-7xl mx-auto px-6">
            <div class="text-center mb-16 reveal-init">
                <span class="inline-flex items-center gap-2 px-4 py-1.5 bg-teal-50 border border-teal-200 rounded-full mb-4">
                    <span class="text-xs font-bold text-teal-700">Features</span>
                </span>
                <h2 class="text-3xl sm:text-4xl font-black text-slate-900 mb-4 tracking-tight">Everything You Need</h2>
                <p class="text-base sm:text-lg text-slate-600 max-w-2xl mx-auto leading-relaxed">
                    A complete suite of tools to manage internship reports, track attendance, and evaluate student performance.
                </p>
            </div>

            <div class="marquee-wrapper py-4 reveal-init">
                <div class="marquee-track items-stretch gap-8">
                    <!-- Set 1 (Original 6 Feature Cards with Full-Surface Colored Backgrounds) -->
                    <!-- Feature 1: Student Module / Daily Log Tracking → Light Blue (#DBEAFE) -->
                    <div class="group w-[300px] sm:w-[350px] md:w-[380px] shrink-0 bg-gradient-to-br from-[#dbeafe] via-[#bfdbfe] to-[#93c5fd] border border-blue-300/90 rounded-3xl p-8 shadow-lg shadow-blue-900/5 hover:shadow-2xl hover:shadow-blue-500/20 hover:border-blue-500 card-hover select-none">
                        <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-blue-600 to-blue-700 flex items-center justify-center text-2xl text-white shadow-lg shadow-blue-600/30 mb-6 group-hover:scale-110 group-hover:rotate-3 transition-transform duration-300">📝</div>
                        <h3 class="text-xl font-bold text-slate-900 mb-3 group-hover:text-blue-900 transition-colors">Daily Log Tracking</h3>
                        <p class="text-sm text-slate-800 leading-relaxed font-medium">
                            Students can submit daily logs with tasks performed, tools used, and skills learned. CU Supervisors can review in real-time.
                        </p>
                    </div>

                    <!-- Feature 2: Weekly Reflection / Evaluation Card → Light Orange (#FFEDD5) -->
                    <div class="group w-[300px] sm:w-[350px] md:w-[380px] shrink-0 bg-gradient-to-br from-[#ffedd5] via-[#fed7aa] to-[#fdba74] border border-orange-300/90 rounded-3xl p-8 shadow-lg shadow-orange-900/5 hover:shadow-2xl hover:shadow-orange-500/20 hover:border-orange-500 card-hover select-none">
                        <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center text-2xl text-white shadow-lg shadow-orange-500/30 mb-6 group-hover:scale-110 group-hover:rotate-3 transition-transform duration-300">📊</div>
                        <h3 class="text-xl font-bold text-slate-900 mb-3 group-hover:text-orange-900 transition-colors">Weekly Reflections</h3>
                        <p class="text-sm text-slate-800 leading-relaxed font-medium">
                            Structured weekly reflection forms help students document their learning journey and achievements.
                        </p>
                    </div>

                    <!-- Feature 3: Company Instructor Card → Light Teal (#CCFBF1) -->
                    <div class="group w-[300px] sm:w-[350px] md:w-[380px] shrink-0 bg-gradient-to-br from-[#ccfbf1] via-[#99f6e4] to-[#5eead4] border border-teal-300/90 rounded-3xl p-8 shadow-lg shadow-teal-900/5 hover:shadow-2xl hover:shadow-teal-500/20 hover:border-teal-500 card-hover select-none">
                        <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-teal-600 to-emerald-700 flex items-center justify-center text-2xl text-white shadow-lg shadow-teal-600/30 mb-6 group-hover:scale-110 group-hover:rotate-3 transition-transform duration-300">👨‍🏫</div>
                        <h3 class="text-xl font-bold text-slate-900 mb-3 group-hover:text-teal-900 transition-colors">Company Instructor Evaluation</h3>
                        <p class="text-sm text-slate-800 leading-relaxed font-medium">
                            Company instructors evaluate student performance through a secure shared link — no account or login required.
                        </p>
                    </div>

                    <!-- Feature 4: Supervisor Card → Light Purple (#EDE9FE) -->
                    <div class="group w-[300px] sm:w-[350px] md:w-[380px] shrink-0 bg-gradient-to-br from-[#ede9fe] via-[#ddd6fe] to-[#c4b5fd] border border-purple-300/90 rounded-3xl p-8 shadow-lg shadow-purple-900/5 hover:shadow-2xl hover:shadow-purple-500/20 hover:border-purple-500 card-hover select-none">
                        <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-purple-600 to-indigo-700 flex items-center justify-center text-2xl text-white shadow-lg shadow-purple-600/30 mb-6 group-hover:scale-110 group-hover:rotate-3 transition-transform duration-300">🎓</div>
                        <h3 class="text-xl font-bold text-slate-900 mb-3 group-hover:text-purple-900 transition-colors">CU Supervisor Grading</h3>
                        <p class="text-sm text-slate-800 leading-relaxed font-medium">
                            CU supervisors assign weekly grades (A-F) with detailed comments on student performance.
                        </p>
                    </div>

                    <!-- Feature 5: Daily Log / Attendance Card → Light Green (#DCFCE7) -->
                    <div class="group w-[300px] sm:w-[350px] md:w-[380px] shrink-0 bg-gradient-to-br from-[#dcfce7] via-[#bbf7d0] to-[#86efac] border border-green-300/90 rounded-3xl p-8 shadow-lg shadow-green-900/5 hover:shadow-2xl hover:shadow-green-500/20 hover:border-green-500 card-hover select-none">
                        <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-emerald-600 to-green-700 flex items-center justify-center text-2xl text-white shadow-lg shadow-emerald-600/30 mb-6 group-hover:scale-110 group-hover:rotate-3 transition-transform duration-300">⚠️</div>
                        <h3 class="text-xl font-bold text-slate-900 mb-3 group-hover:text-green-900 transition-colors">Progress Alerts</h3>
                        <p class="text-sm text-slate-800 leading-relaxed font-medium">
                            Automatic email alerts notify CU supervisors when students fall behind schedule on their daily logs.
                        </p>
                    </div>

                    <!-- Feature 6: Report Management Card → Light Indigo (#E0E7FF) -->
                    <div class="group w-[300px] sm:w-[350px] md:w-[380px] shrink-0 bg-gradient-to-br from-[#e0e7ff] via-[#c7d2fe] to-[#a5b4fc] border border-indigo-300/90 rounded-3xl p-8 shadow-lg shadow-indigo-900/5 hover:shadow-2xl hover:shadow-indigo-500/20 hover:border-indigo-500 card-hover select-none">
                        <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-indigo-600 to-violet-700 flex items-center justify-center text-2xl text-white shadow-lg shadow-indigo-600/30 mb-6 group-hover:scale-110 group-hover:rotate-3 transition-transform duration-300">📈</div>
                        <h3 class="text-xl font-bold text-slate-900 mb-3 group-hover:text-indigo-900 transition-colors">Analytics Dashboard</h3>
                        <p class="text-sm text-slate-800 leading-relaxed font-medium">
                            Comprehensive dashboards with real-time statistics, attendance tracking, and performance metrics.
                        </p>
                    </div>

                    <!-- Set 2 (Duplicate for Seamless Continuous Loop) -->
                    <!-- Feature 1 Duplicate: Student Card → Light Blue (#DBEAFE) -->
                    <div class="group w-[300px] sm:w-[350px] md:w-[380px] shrink-0 bg-gradient-to-br from-[#dbeafe] via-[#bfdbfe] to-[#93c5fd] border border-blue-300/90 rounded-3xl p-8 shadow-lg shadow-blue-900/5 hover:shadow-2xl hover:shadow-blue-500/20 hover:border-blue-500 card-hover select-none" aria-hidden="true">
                        <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-blue-600 to-blue-700 flex items-center justify-center text-2xl text-white shadow-lg shadow-blue-600/30 mb-6 group-hover:scale-110 group-hover:rotate-3 transition-transform duration-300">📝</div>
                        <h3 class="text-xl font-bold text-slate-900 mb-3 group-hover:text-blue-900 transition-colors">Daily Log Tracking</h3>
                        <p class="text-sm text-slate-800 leading-relaxed font-medium">
                            Students can submit daily logs with tasks performed, tools used, and skills learned. CU Supervisors can review in real-time.
                        </p>
                    </div>

                    <!-- Feature 2 Duplicate: Weekly Reflection / Evaluation Card → Light Orange (#FFEDD5) -->
                    <div class="group w-[300px] sm:w-[350px] md:w-[380px] shrink-0 bg-gradient-to-br from-[#ffedd5] via-[#fed7aa] to-[#fdba74] border border-orange-300/90 rounded-3xl p-8 shadow-lg shadow-orange-900/5 hover:shadow-2xl hover:shadow-orange-500/20 hover:border-orange-500 card-hover select-none" aria-hidden="true">
                        <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center text-2xl text-white shadow-lg shadow-orange-500/30 mb-6 group-hover:scale-110 group-hover:rotate-3 transition-transform duration-300">📊</div>
                        <h3 class="text-xl font-bold text-slate-900 mb-3 group-hover:text-orange-900 transition-colors">Weekly Reflections</h3>
                        <p class="text-sm text-slate-800 leading-relaxed font-medium">
                            Structured weekly reflection forms help students document their learning journey and achievements.
                        </p>
                    </div>

                    <!-- Feature 3 Duplicate: Company Instructor Card → Light Teal (#CCFBF1) -->
                    <div class="group w-[300px] sm:w-[350px] md:w-[380px] shrink-0 bg-gradient-to-br from-[#ccfbf1] via-[#99f6e4] to-[#5eead4] border border-teal-300/90 rounded-3xl p-8 shadow-lg shadow-teal-900/5 hover:shadow-2xl hover:shadow-teal-500/20 hover:border-teal-500 card-hover select-none" aria-hidden="true">
                        <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-teal-600 to-emerald-700 flex items-center justify-center text-2xl text-white shadow-lg shadow-teal-600/30 mb-6 group-hover:scale-110 group-hover:rotate-3 transition-transform duration-300">👨‍🏫</div>
                        <h3 class="text-xl font-bold text-slate-900 mb-3 group-hover:text-teal-900 transition-colors">Company Instructor Evaluation</h3>
                        <p class="text-sm text-slate-800 leading-relaxed font-medium">
                            Company instructors evaluate student performance through a secure shared link — no account or login required.
                        </p>
                    </div>

                    <!-- Feature 4 Duplicate: Supervisor Card → Light Purple (#EDE9FE) -->
                    <div class="group w-[300px] sm:w-[350px] md:w-[380px] shrink-0 bg-gradient-to-br from-[#ede9fe] via-[#ddd6fe] to-[#c4b5fd] border border-purple-300/90 rounded-3xl p-8 shadow-lg shadow-purple-900/5 hover:shadow-2xl hover:shadow-purple-500/20 hover:border-purple-500 card-hover select-none" aria-hidden="true">
                        <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-purple-600 to-indigo-700 flex items-center justify-center text-2xl text-white shadow-lg shadow-purple-600/30 mb-6 group-hover:scale-110 group-hover:rotate-3 transition-transform duration-300">🎓</div>
                        <h3 class="text-xl font-bold text-slate-900 mb-3 group-hover:text-purple-900 transition-colors">CU Supervisor Grading</h3>
                        <p class="text-sm text-slate-800 leading-relaxed font-medium">
                            CU supervisors assign weekly grades (A-F) with detailed comments on student performance.
                        </p>
                    </div>

                    <!-- Feature 5 Duplicate: Daily Log / Attendance Card → Light Green (#DCFCE7) -->
                    <div class="group w-[300px] sm:w-[350px] md:w-[380px] shrink-0 bg-gradient-to-br from-[#dcfce7] via-[#bbf7d0] to-[#86efac] border border-green-300/90 rounded-3xl p-8 shadow-lg shadow-green-900/5 hover:shadow-2xl hover:shadow-green-500/20 hover:border-green-500 card-hover select-none" aria-hidden="true">
                        <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-emerald-600 to-green-700 flex items-center justify-center text-2xl text-white shadow-lg shadow-emerald-600/30 mb-6 group-hover:scale-110 group-hover:rotate-3 transition-transform duration-300">⚠️</div>
                        <h3 class="text-xl font-bold text-slate-900 mb-3 group-hover:text-green-900 transition-colors">Progress Alerts</h3>
                        <p class="text-sm text-slate-800 leading-relaxed font-medium">
                            Automatic email alerts notify CU supervisors when students fall behind schedule on their daily logs.
                        </p>
                    </div>

                    <!-- Feature 6 Duplicate: Report Management Card → Light Indigo (#E0E7FF) -->
                    <div class="group w-[300px] sm:w-[350px] md:w-[380px] shrink-0 bg-gradient-to-br from-[#e0e7ff] via-[#c7d2fe] to-[#a5b4fc] border border-indigo-300/90 rounded-3xl p-8 shadow-lg shadow-indigo-900/5 hover:shadow-2xl hover:shadow-indigo-500/20 hover:border-indigo-500 card-hover select-none" aria-hidden="true">
                        <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-indigo-600 to-violet-700 flex items-center justify-center text-2xl text-white shadow-lg shadow-indigo-600/30 mb-6 group-hover:scale-110 group-hover:rotate-3 transition-transform duration-300">📈</div>
                        <h3 class="text-xl font-bold text-slate-900 mb-3 group-hover:text-indigo-900 transition-colors">Analytics Dashboard</h3>
                        <p class="text-sm text-slate-800 leading-relaxed font-medium">
                            Comprehensive dashboards with real-time statistics, attendance tracking, and performance metrics.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ════ HOW IT WORKS SECTION ════ -->
    <section id="how-it-works" class="py-24 bg-gradient-to-b from-slate-50/80 via-white to-slate-50/80 border-t border-slate-100">
        <div class="max-w-7xl mx-auto px-6">
            <div class="text-center mb-16 reveal-init">
                <span class="inline-flex items-center gap-2 px-4 py-1.5 bg-teal-50 border border-teal-200 rounded-full mb-4">
                    <span class="text-xs font-bold text-teal-700">Workflow</span>
                </span>
                <h2 class="text-3xl sm:text-4xl font-black text-slate-900 mb-4 tracking-tight">How InternReport Works</h2>
                <p class="text-base sm:text-lg text-slate-600 max-w-2xl mx-auto leading-relaxed">
                    A guided workflow from start to finish designed for student success and university compliance.
                </p>
            </div>

            <div class="grid md:grid-cols-2 lg:grid-cols-5 gap-6 reveal-init">
                <!-- Step 1 -->
                <div class="p-6 rounded-3xl bg-white border border-slate-200/80 shadow-md shadow-slate-900/5 hover:shadow-xl hover:border-teal-300 card-hover flex flex-col justify-between group">
                    <div>
                        <div class="w-12 h-12 rounded-2xl bg-teal-100 text-teal-700 flex items-center justify-center text-xl font-black mb-5 group-hover:scale-110 transition-transform">1</div>
                        <h3 class="text-lg font-bold text-slate-900 mb-2 group-hover:text-teal-700 transition-colors">Student</h3>
                        <p class="text-xs text-slate-600 leading-relaxed">Submits work throughout the internship</p>
                    </div>
                    <div class="mt-4 pt-3 border-t border-slate-100 text-xs font-bold text-teal-700 flex items-center gap-1">
                        <span>Portal Access</span> <span>→</span>
                    </div>
                </div>

                <!-- Step 2 -->
                <div class="p-6 rounded-3xl bg-white border border-slate-200/80 shadow-md shadow-slate-900/5 hover:shadow-xl hover:border-emerald-300 card-hover flex flex-col justify-between group">
                    <div>
                        <div class="w-12 h-12 rounded-2xl bg-emerald-100 text-emerald-700 flex items-center justify-center text-xl font-black mb-5 group-hover:scale-110 transition-transform">2</div>
                        <h3 class="text-lg font-bold text-slate-900 mb-2 group-hover:text-emerald-700 transition-colors">Daily Logs</h3>
                        <p class="text-xs text-slate-600 leading-relaxed">Records tasks, hours, and skills learned</p>
                    </div>
                    <div class="mt-4 pt-3 border-t border-slate-100 text-xs font-bold text-emerald-700 flex items-center gap-1">
                        <span>Daily Tracking</span> <span>→</span>
                    </div>
                </div>

                <!-- Step 3 -->
                <div class="p-6 rounded-3xl bg-white border border-slate-200/80 shadow-md shadow-slate-900/5 hover:shadow-xl hover:border-blue-300 card-hover flex flex-col justify-between group">
                    <div>
                        <div class="w-12 h-12 rounded-2xl bg-blue-100 text-blue-700 flex items-center justify-center text-xl font-black mb-5 group-hover:scale-110 transition-transform">3</div>
                        <h3 class="text-lg font-bold text-slate-900 mb-2 group-hover:text-blue-700 transition-colors">Weekly Reflection</h3>
                        <p class="text-xs text-slate-600 leading-relaxed">Documents learning and achievements</p>
                    </div>
                    <div class="mt-4 pt-3 border-t border-slate-100 text-xs font-bold text-blue-700 flex items-center gap-1">
                        <span>Self Reflection</span> <span>→</span>
                    </div>
                </div>

                <!-- Step 4 -->
                <div class="p-6 rounded-3xl bg-white border border-slate-200/80 shadow-md shadow-slate-900/5 hover:shadow-xl hover:border-purple-300 card-hover flex flex-col justify-between group">
                    <div>
                        <div class="w-12 h-12 rounded-2xl bg-purple-100 text-purple-700 flex items-center justify-center text-xl font-black mb-5 group-hover:scale-110 transition-transform">4</div>
                        <h3 class="text-lg font-bold text-slate-900 mb-2 group-hover:text-purple-700 transition-colors">Company Instructor Review</h3>
                        <p class="text-xs text-slate-600 leading-relaxed">Reviews and approves submissions</p>
                    </div>
                    <div class="mt-4 pt-3 border-t border-slate-100 text-xs font-bold text-purple-700 flex items-center gap-1">
                        <span>Magic Link</span> <span>→</span>
                    </div>
                </div>

                <!-- Step 5 -->
                <div class="p-6 rounded-3xl bg-white border border-slate-200/80 shadow-md shadow-slate-900/5 hover:shadow-xl hover:border-indigo-300 card-hover flex flex-col justify-between group">
                    <div>
                        <div class="w-12 h-12 rounded-2xl bg-indigo-100 text-indigo-700 flex items-center justify-center text-xl font-black mb-5 group-hover:scale-110 transition-transform">5</div>
                        <h3 class="text-lg font-bold text-slate-900 mb-2 group-hover:text-indigo-700 transition-colors">CU Supervisor Grading</h3>
                        <p class="text-xs text-slate-600 leading-relaxed">Assigns weekly grades with feedback</p>
                    </div>
                    <div class="mt-4 pt-3 border-t border-slate-100 text-xs font-bold text-indigo-700 flex items-center gap-1">
                        <span>Final Grade</span> <span>🎉</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ════ ROLES SECTION ════ -->
    <section id="roles" class="py-24 bg-gradient-to-b from-slate-50 via-teal-50/20 to-slate-50 border-t border-slate-100">
        <div class="max-w-7xl mx-auto px-6">
            <div class="text-center mb-16 reveal-init">
                <span class="inline-flex items-center gap-2 px-4 py-1.5 bg-emerald-50 border border-emerald-200 rounded-full mb-4">
                    <span class="text-xs font-bold text-emerald-700">For Everyone</span>
                </span>
                <h2 class="text-3xl sm:text-4xl font-black text-slate-900 mb-4 tracking-tight">Built for All Roles</h2>
                <p class="text-base sm:text-lg text-slate-600 max-w-2xl mx-auto leading-relaxed">
                    Three authenticated roles — Student, CU Supervisor, and Administrator — plus a company Instructor who reviews through a secure link.
                </p>
            </div>

            <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-8">
                <!-- Student Card -->
                <div class="bg-white rounded-3xl shadow-xl shadow-slate-900/5 border border-slate-200/80 overflow-hidden card-hover transition-all duration-300 flex flex-col justify-between reveal-init">
                    <div>
                        <div class="h-2 bg-gradient-to-r from-blue-600 to-cyan-600"></div>
                        <div class="p-8">
                            <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-blue-600 to-cyan-700 flex items-center justify-center text-3xl text-white shadow-lg shadow-blue-600/25 mb-6">🎓</div>
                            <h3 class="text-2xl font-bold text-slate-900 mb-3">Students</h3>
                            <p class="text-sm text-slate-600 mb-6 leading-relaxed">
                                Track your internship journey, submit daily logs, write weekly reflections, and monitor your progress.
                            </p>
                            <ul class="space-y-3 mb-8">
                                <li class="flex items-center gap-3 text-sm text-slate-700">
                                    <span class="w-5 h-5 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center text-xs font-bold">✓</span>
                                    Daily log submissions
                                </li>
                                <li class="flex items-center gap-3 text-sm text-slate-700">
                                    <span class="w-5 h-5 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center text-xs font-bold">✓</span>
                                    Weekly reflections
                                </li>
                                <li class="flex items-center gap-3 text-sm text-slate-700">
                                    <span class="w-5 h-5 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center text-xs font-bold">✓</span>
                                    Progress tracking
                                </li>
                            </ul>
                        </div>
                    </div>
                    <div class="p-8 pt-0">
                        <a href="login.php" class="block w-full text-center px-6 py-3.5 bg-gradient-to-r from-blue-600 to-cyan-700 hover:from-blue-700 hover:to-cyan-800 text-white font-bold rounded-xl shadow-lg shadow-blue-600/25 hover:shadow-blue-600/40 hover:scale-[1.02] active:scale-[0.98] transition-all duration-200 cursor-pointer">
                            Student Login →
                        </a>
                    </div>
                </div>

                <!-- Supervisor Card -->
                <div class="bg-white rounded-3xl shadow-xl shadow-slate-900/5 border border-slate-200/80 overflow-hidden card-hover transition-all duration-300 flex flex-col justify-between reveal-init">
                    <div>
                        <div class="h-2 bg-gradient-to-r from-purple-600 to-indigo-600"></div>
                        <div class="p-8">
                            <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-purple-600 to-indigo-700 flex items-center justify-center text-3xl text-white shadow-lg shadow-purple-600/25 mb-6">👨‍💼</div>
                            <h3 class="text-2xl font-bold text-slate-900 mb-3">CU Supervisors</h3>
                            <p class="text-sm text-slate-600 mb-6 leading-relaxed">
                                Monitor student progress, assign weekly grades, and receive alerts when students need attention.
                            </p>
                            <ul class="space-y-3 mb-8">
                                <li class="flex items-center gap-3 text-sm text-slate-700">
                                    <span class="w-5 h-5 rounded-full bg-purple-100 text-purple-700 flex items-center justify-center text-xs font-bold">✓</span>
                                    Student monitoring
                                </li>
                                <li class="flex items-center gap-3 text-sm text-slate-700">
                                    <span class="w-5 h-5 rounded-full bg-purple-100 text-purple-700 flex items-center justify-center text-xs font-bold">✓</span>
                                    Weekly grading
                                </li>
                                <li class="flex items-center gap-3 text-sm text-slate-700">
                                    <span class="w-5 h-5 rounded-full bg-purple-100 text-purple-700 flex items-center justify-center text-xs font-bold">✓</span>
                                    Alerts & notifications
                                </li>
                            </ul>
                        </div>
                    </div>
                    <div class="p-8 pt-0">
                        <a href="login.php" class="block w-full text-center px-6 py-3.5 bg-gradient-to-r from-purple-600 to-indigo-700 hover:from-purple-700 hover:to-indigo-800 text-white font-bold rounded-xl shadow-lg shadow-purple-600/25 hover:shadow-purple-600/40 hover:scale-[1.02] active:scale-[0.98] transition-all duration-200 cursor-pointer">
                            Supervisor Login →
                        </a>
                    </div>
                </div>

                <!-- Instructor Card -->
                <div class="bg-white rounded-3xl shadow-xl shadow-slate-900/5 border border-slate-200/80 overflow-hidden card-hover transition-all duration-300 flex flex-col justify-between reveal-init">
                    <div>
                        <div class="h-2 bg-gradient-to-r from-teal-600 to-emerald-600"></div>
                        <div class="p-8">
                            <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-teal-600 to-emerald-700 flex items-center justify-center text-3xl text-white shadow-lg shadow-teal-600/25 mb-6">👨‍🏫</div>
                            <h3 class="text-2xl font-bold text-slate-900 mb-3">Company Instructors</h3>
                            <p class="text-sm text-slate-600 mb-6 leading-relaxed">
                                Evaluate student weekly performance through a secure link. No account creation or password needed.
                            </p>
                            <ul class="space-y-3 mb-8">
                                <li class="flex items-center gap-3 text-sm text-slate-700">
                                    <span class="w-5 h-5 rounded-full bg-teal-100 text-teal-700 flex items-center justify-center text-xs font-bold">✓</span>
                                    No login required
                                </li>
                                <li class="flex items-center gap-3 text-sm text-slate-700">
                                    <span class="w-5 h-5 rounded-full bg-teal-100 text-teal-700 flex items-center justify-center text-xs font-bold">✓</span>
                                    Weekly evaluations
                                </li>
                                <li class="flex items-center gap-3 text-sm text-slate-700">
                                    <span class="w-5 h-5 rounded-full bg-teal-100 text-teal-700 flex items-center justify-center text-xs font-bold">✓</span>
                                    Performance feedback
                                </li>
                            </ul>
                        </div>
                    </div>
                    <div class="p-8 pt-0">
                        <div class="w-full text-center px-4 py-3 bg-teal-50 border border-teal-200/80 rounded-xl text-xs font-bold text-teal-800 select-none">
                            🔗 Magic Link Access (No Login Required)
                        </div>
                    </div>
                </div>

                <!-- Admin Card -->
                <div class="bg-white rounded-3xl shadow-xl shadow-slate-900/5 border border-slate-200/80 overflow-hidden card-hover transition-all duration-300 flex flex-col justify-between reveal-init">
                    <div>
                        <div class="h-2 bg-gradient-to-r from-slate-700 to-slate-900"></div>
                        <div class="p-8">
                            <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-slate-800 to-slate-950 flex items-center justify-center text-3xl text-white shadow-lg shadow-slate-800/25 mb-6">⚙️</div>
                            <h3 class="text-2xl font-bold text-slate-900 mb-3">Administrators</h3>
                            <p class="text-sm text-slate-600 mb-6 leading-relaxed">
                                Complete system control. Manage users, companies, settings, and access detailed analytics.
                            </p>
                            <ul class="space-y-3 mb-8">
                                <li class="flex items-center gap-3 text-sm text-slate-700">
                                    <span class="w-5 h-5 rounded-full bg-slate-100 text-slate-700 flex items-center justify-center text-xs font-bold">✓</span>
                                    User management
                                </li>
                                <li class="flex items-center gap-3 text-sm text-slate-700">
                                    <span class="w-5 h-5 rounded-full bg-slate-100 text-slate-700 flex items-center justify-center text-xs font-bold">✓</span>
                                    Company oversight
                                </li>
                                <li class="flex items-center gap-3 text-sm text-slate-700">
                                    <span class="w-5 h-5 rounded-full bg-slate-100 text-slate-700 flex items-center justify-center text-xs font-bold">✓</span>
                                    System analytics
                                </li>
                            </ul>
                        </div>
                    </div>
                    <div class="p-8 pt-0">
                        <a href="login.php" class="block w-full text-center px-6 py-3.5 bg-gradient-to-r from-slate-800 to-slate-950 hover:from-slate-900 hover:to-black text-white font-bold rounded-xl shadow-lg shadow-slate-900/25 hover:shadow-slate-900/40 hover:scale-[1.02] active:scale-[0.98] transition-all duration-200 cursor-pointer">
                            Admin Login →
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ════ ABOUT SECTION ════ -->
    <section id="about" class="py-24 bg-white border-t border-slate-100">
        <div class="max-w-7xl mx-auto px-6">
            <div class="grid lg:grid-cols-2 gap-16 items-center">
                <div class="space-y-6 reveal-init">
                    <span class="inline-flex items-center gap-2 px-4 py-1.5 bg-teal-50 border border-teal-200 rounded-full">
                        <span class="text-xs font-bold text-teal-700">About InternReport</span>
                    </span>
                    <h2 class="text-3xl sm:text-4xl font-black text-slate-900 tracking-tight leading-tight">
                        Bridging University Education and Industry Experience
                    </h2>
                    <p class="text-base text-slate-600 leading-relaxed">
                        InternReport was created to streamline the internship management process for universities and educational institutions. It replaces paper logs, scattered emails, and manual grading with a single, modern web application.
                    </p>
                    <div class="space-y-4 pt-2">
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-xl bg-teal-100 text-teal-700 flex items-center justify-center text-base shrink-0 mt-1 font-bold">🎯</div>
                            <div>
                                <h4 class="font-bold text-slate-900 text-base">Structured Learning</h4>
                                <p class="text-sm text-slate-600 leading-relaxed">Ensure students document daily tasks, tools used, and competencies developed throughout their placement.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-xl bg-blue-100 text-blue-700 flex items-center justify-center text-base shrink-0 mt-1 font-bold">⚡</div>
                            <div>
                                <h4 class="font-bold text-slate-900 text-base">Effortless Evaluation</h4>
                                <p class="text-sm text-slate-600 leading-relaxed">Company instructors review reports via one-click secure links without needing account creation.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-xl bg-purple-100 text-purple-700 flex items-center justify-center text-base shrink-0 mt-1 font-bold">📊</div>
                            <div>
                                <h4 class="font-bold text-slate-900 text-base">Academic Accountability</h4>
                                <p class="text-sm text-slate-600 leading-relaxed">CU supervisors assign standardized grades (A-F), leave detailed commentary, and track attendance rates.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="relative reveal-init select-none">
                    <div class="bg-gradient-to-br from-slate-900 via-slate-800 to-teal-950 rounded-3xl p-7 text-white shadow-2xl shadow-slate-900/20 border border-slate-700/60">
                        <div class="flex items-center justify-between mb-6 pb-4 border-b border-slate-700/70">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-xl bg-teal-500/20 border border-teal-400/30 flex items-center justify-center text-teal-300 text-base">
                                    🏛️
                                </div>
                                <div>
                                    <h3 class="text-base font-bold text-white tracking-tight">Platform Architecture</h3>
                                    <p class="text-[0.6875rem] text-slate-400">Integrated Internship Lifecycle Flow</p>
                                </div>
                            </div>
                            <span class="px-2.5 py-1 bg-emerald-500/20 text-emerald-300 text-[0.625rem] font-black uppercase tracking-wider rounded-full border border-emerald-400/30 flex items-center gap-1.5">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span> Live System
                            </span>
                        </div>

                        <!-- Architecture Diagram Pipeline -->
                        <div class="relative pl-6 space-y-4 before:absolute before:left-2.5 before:top-2 before:bottom-2 before:w-0.5 before:bg-gradient-to-b before:from-teal-500 before:via-blue-500 before:to-purple-500">
                            <!-- Node 1 -->
                            <div class="relative">
                                <div class="absolute -left-6 top-1 w-3 h-3 rounded-full bg-teal-400 border-2 border-slate-900"></div>
                                <div class="p-3 bg-slate-800/60 rounded-xl border border-slate-700/50">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-2">
                                            <span class="text-sm">📝</span>
                                            <span class="text-xs font-bold text-slate-200">1. Student Log Entry</span>
                                        </div>
                                        <span class="text-[0.625rem] font-bold text-teal-400 bg-teal-950/60 border border-teal-800/60 px-2 py-0.5 rounded-md">Daily &amp; Weekly</span>
                                    </div>
                                    <p class="text-[0.6875rem] text-slate-400 mt-1 pl-6">Students record work tasks, hours, and competencies.</p>
                                </div>
                            </div>

                            <!-- Node 2 -->
                            <div class="relative">
                                <div class="absolute -left-6 top-1 w-3 h-3 rounded-full bg-emerald-400 border-2 border-slate-900"></div>
                                <div class="p-3 bg-slate-800/60 rounded-xl border border-slate-700/50">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-2">
                                            <span class="text-sm">🔗</span>
                                            <span class="text-xs font-bold text-slate-200">2. Company Instructor Review</span>
                                        </div>
                                        <span class="text-[0.625rem] font-bold text-emerald-400 bg-emerald-950/60 border border-emerald-800/60 px-2 py-0.5 rounded-md">Token-Secured Link</span>
                                    </div>
                                    <p class="text-[0.6875rem] text-slate-400 mt-1 pl-6">Company instructors grade &amp; sign with zero login required.</p>
                                </div>
                            </div>

                            <!-- Node 3 -->
                            <div class="relative">
                                <div class="absolute -left-6 top-1 w-3 h-3 rounded-full bg-blue-400 border-2 border-slate-900"></div>
                                <div class="p-3 bg-slate-800/60 rounded-xl border border-slate-700/50">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-2">
                                            <span class="text-sm">🎓</span>
                                            <span class="text-xs font-bold text-slate-200">3. CU Supervisor Evaluation</span>
                                        </div>
                                        <span class="text-[0.625rem] font-bold text-blue-400 bg-blue-950/60 border border-blue-800/60 px-2 py-0.5 rounded-md">A-F Standardized</span>
                                    </div>
                                    <p class="text-[0.6875rem] text-slate-400 mt-1 pl-6">University supervisors review and assign academic credit.</p>
                                </div>
                            </div>

                            <!-- Node 4 -->
                            <div class="relative">
                                <div class="absolute -left-6 top-1 w-3 h-3 rounded-full bg-purple-400 border-2 border-slate-900"></div>
                                <div class="p-3 bg-slate-800/60 rounded-xl border border-slate-700/50">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-2">
                                            <span class="text-sm">⚙️</span>
                                            <span class="text-xs font-bold text-slate-200">4. Admin Governance</span>
                                        </div>
                                        <span class="text-[0.625rem] font-bold text-purple-400 bg-purple-950/60 border border-purple-800/60 px-2 py-0.5 rounded-md">Full Governance</span>
                                    </div>
                                    <p class="text-[0.6875rem] text-slate-400 mt-1 pl-6">Department admins manage academic years, users, and audit logs.</p>
                                </div>
                            </div>
                        </div>

                        <!-- Diagram Footer Specs -->
                        <div class="mt-5 pt-3.5 border-t border-slate-700/70 flex items-center justify-between text-[0.6875rem] text-slate-400">
                            <span class="flex items-center gap-1.5 text-slate-300">
                                <span>🔒</span> Single-Use Magic Links
                            </span>
                            <span class="text-teal-400 font-bold">100% Paperless</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ════ CTA SECTION ════ -->
    <section class="py-20 bg-gradient-to-r from-teal-700 via-teal-800 to-blue-900 text-white relative overflow-hidden">
        <div class="absolute inset-0 opacity-10 bg-[radial-gradient(#fff_1px,transparent_1px)] [background-size:16px_16px]"></div>
        <div class="relative max-w-4xl mx-auto px-6 text-center reveal-init space-y-6">
            <h2 class="text-3xl sm:text-4xl font-black tracking-tight">Ready to Streamline Internship Management?</h2>
            <p class="text-base sm:text-lg text-teal-100 max-w-xl mx-auto leading-relaxed">
                Join students, CU supervisors, and company instructors already using InternReport for a transparent, efficient internship experience.
            </p>
            <div class="pt-2">
                <a href="login.php" class="inline-flex items-center gap-2 px-8 py-4 bg-white hover:bg-teal-50 text-teal-800 font-bold text-base rounded-2xl shadow-xl hover:shadow-2xl hover:scale-105 active:scale-95 transition-all duration-300">
                    <span>Sign In to InternReport</span>
                    <span class="text-lg">→</span>
                </a>
            </div>
        </div>
    </section>

    <!-- ════ FOOTER ════ -->
    <footer class="bg-slate-900 text-slate-400 py-16 border-t border-slate-800">
        <div class="max-w-7xl mx-auto px-6">
            <div class="grid md:grid-cols-4 gap-12 mb-12">
                <div>
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-teal-500 to-emerald-600 flex items-center justify-center text-white text-base shadow-sm">📋</div>
                        <span class="text-lg font-black text-white tracking-tight">InternReport</span>
                    </div>
                    <p class="text-sm leading-relaxed">
                        A centralized internship report management system for students, CU supervisors, company instructors, and administrators.
                    </p>
                </div>
                <div>
                    <h4 class="text-white font-bold mb-4">Quick Links</h4>
                    <ul class="space-y-2.5">
                        <li><a href="#features" class="text-sm hover:text-white transition-colors duration-200">Features</a></li>
                        <li><a href="#how-it-works" class="text-sm hover:text-white transition-colors duration-200">How It Works</a></li>
                        <li><a href="#roles" class="text-sm hover:text-white transition-colors duration-200">Roles</a></li>
                        <li><a href="#about" class="text-sm hover:text-white transition-colors duration-200">About</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="text-white font-bold mb-4">Login</h4>
                    <ul class="space-y-2.5">
                        <li><a href="login.php" class="text-sm hover:text-white transition-colors duration-200">Student Login</a></li>
                        <li><a href="login.php" class="text-sm hover:text-white transition-colors duration-200">CU Supervisor Login</a></li>
                        <li><a href="login.php" class="text-sm hover:text-white transition-colors duration-200">Admin Login</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="text-white font-bold mb-4">Contact</h4>
                    <ul class="space-y-2.5">
                        <li class="text-sm">📧 support@internreport.com</li>
                    </ul>
                </div>
            </div>
            <div class="border-t border-slate-800 pt-8 text-center">
                <p class="text-sm text-slate-500">
                    © <?= date('Y') ?> InternReport. All rights reserved. Built with ❤️ for education.
                </p>
            </div>
        </div>
    </footer>

    <!-- ════ INTERACTIVE ENHANCEMENTS JAVASCRIPT ════ -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // 1. Scroll Reveal Animations (IntersectionObserver)
            const reveals = document.querySelectorAll('.reveal-init');
            const observer = new IntersectionObserver((entries, obs) => {
                entries.forEach((entry, idx) => {
                    if (entry.isIntersecting) {
                        setTimeout(() => {
                            entry.target.classList.add('reveal-visible');
                        }, 60);
                        obs.unobserve(entry.target);
                    }
                });
            }, {
                threshold: 0.1,
                rootMargin: '0px 0px -30px 0px'
            });

            reveals.forEach(el => observer.observe(el));

            // 2. Smooth Number Counter Animation
            const countElements = document.querySelectorAll('[data-target-count]');
            const counterObserver = new IntersectionObserver((entries, obs) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const target = parseInt(entry.target.getAttribute('data-target-count'), 10);
                        const suffix = entry.target.getAttribute('data-suffix') || '';
                        if (!isNaN(target) && target > 0) {
                            let start = 0;
                            const duration = 1000;
                            const stepTime = 25;
                            const steps = duration / stepTime;
                            const increment = target / steps;
                            const timer = setInterval(() => {
                                start += increment;
                                if (start >= target) {
                                    entry.target.textContent = target + suffix;
                                    clearInterval(timer);
                                } else {
                                    entry.target.textContent = Math.floor(start) + suffix;
                                }
                            }, stepTime);
                        }
                        obs.unobserve(entry.target);
                    }
                });
            }, {
                threshold: 0.2
            });

            countElements.forEach(el => counterObserver.observe(el));

            // 3. Navbar Scroll Shadow & Blur Elevation
            const navbar = document.getElementById('main-nav');
            if (navbar) {
                window.addEventListener('scroll', () => {
                    if (window.scrollY > 20) {
                        navbar.classList.add('shadow-md', 'shadow-slate-900/5', 'bg-white/95');
                        navbar.classList.remove('bg-white/80');
                    } else {
                        navbar.classList.remove('shadow-md', 'shadow-slate-900/5', 'bg-white/95');
                        navbar.classList.add('bg-white/80');
                    }
                }, {
                    passive: true
                });
            }
        });
    </script>

</body>

</html>