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
    <title>Internship Report Management System | Polytechnic University (Faculty of Computing)</title>
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
                    colors: {
                        brand: {
                            50: '#f0fdfa',
                            100: '#ccfbf1',
                            500: '#14b8a6',
                            600: '#0d9488',
                            700: '#0f766e',
                            800: '#115e59',
                            900: '#134e4a',
                            950: '#042f2e',
                        }
                    }
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

        /* Floating background blobs */
        @keyframes float-slow {

            0%,
            100% {
                transform: translate(0px, 0px) scale(1);
            }

            50% {
                transform: translate(20px, -25px) scale(1.08);
            }
        }

        @keyframes float-reverse {

            0%,
            100% {
                transform: translate(0px, 0px) scale(1);
            }

            50% {
                transform: translate(-25px, 20px) scale(0.94);
            }
        }

        .animate-float-slow {
            animation: float-slow 14s ease-in-out infinite;
        }

        .animate-float-reverse {
            animation: float-reverse 18s ease-in-out infinite;
        }

        /* Scroll reveal animations */
        .reveal-init {
            opacity: 0;
            transform: translateY(24px);
            transition: opacity 0.7s cubic-bezier(0.16, 1, 0.3, 1), transform 0.7s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .reveal-visible {
            opacity: 1;
            transform: translateY(0);
        }

        .nav-link-hover {
            position: relative;
        }

        .nav-link-hover::after {
            content: '';
            position: absolute;
            bottom: -4px;
            left: 0;
            width: 0;
            height: 2px;
            background: linear-gradient(90deg, #0d9488, #2563eb);
            border-radius: 9999px;
            transition: width 0.25s ease-out;
        }

        .nav-link-hover:hover::after {
            width: 100%;
        }
    </style>
</head>

<body class="bg-[#f8fafc] font-sans antialiased text-slate-900 selection:bg-teal-600 selection:text-white flex flex-col min-h-screen">

    <!-- ════ UNIVERSITY NAVIGATION BAR ════ -->
    <nav id="main-nav" class="fixed top-0 left-0 right-0 z-50 bg-white/85 backdrop-blur-xl border-b border-slate-200/80 transition-all duration-300">
        <div class="max-w-7xl mx-auto px-6 py-4">
            <div class="flex items-center justify-between">

                <!-- University Brand & Logo -->
                <a href="#" class="flex items-center gap-3.5 group">
                    <div class="w-11 h-11 rounded-2xl bg-gradient-to-br from-teal-700 via-teal-800 to-slate-900 flex items-center justify-center shadow-md shadow-teal-900/20 group-hover:scale-105 transition-transform duration-300">
                        <span class="text-white text-xl">🎓</span>
                    </div>
                    <div>
                        <span class="text-lg sm:text-xl font-black text-slate-900 tracking-tight block group-hover:text-teal-700 transition-colors duration-200 leading-none">
                            InternReport
                        </span>
                        <span class="text-[10px] font-extrabold uppercase tracking-widest text-teal-700 block mt-1">
                            Polytechnic University
                        </span>
                    </div>
                </a>

                <!-- Right Nav Items -->
                <div class="flex items-center gap-6 sm:gap-8">
                    <div class="hidden md:flex items-center gap-6 text-xs font-bold text-slate-600">
                        <span class="px-3 py-1 bg-slate-100 border border-slate-200/90 rounded-full text-slate-600 font-semibold flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-emerald-500"></span> 2025–2026 Academic Year
                        </span>
                        <a href="#how-it-works" class="nav-link-hover text-slate-700 hover:text-teal-700 transition-colors text-sm">How It Works</a>
                    </div>

                    <a href="login.php" class="px-6 py-2.5 bg-gradient-to-r from-teal-700 via-teal-800 to-slate-900 hover:from-teal-800 hover:to-black text-white text-sm font-bold rounded-xl shadow-lg shadow-teal-900/20 hover:shadow-teal-900/35 hover:scale-[1.03] active:scale-[0.98] transition-all duration-200 cursor-pointer inline-flex items-center gap-2">
                        <span>Sign In</span>
                        <span class="text-xs">→</span>
                    </a>
                </div>

            </div>
        </div>
    </nav>

    <!-- ════ HERO SECTION ════ -->
    <section class="relative flex-1 flex items-center justify-center overflow-hidden pt-32 pb-20">
        <!-- Ambient University Academic Accents -->
        <div class="absolute inset-0 bg-gradient-to-b from-slate-50 via-teal-50/20 to-slate-50 pointer-events-none"></div>
        <div class="absolute top-24 left-10 w-96 h-96 bg-teal-200/20 rounded-full blur-3xl animate-float-slow pointer-events-none"></div>
        <div class="absolute bottom-16 right-10 w-[30rem] h-[30rem] bg-blue-200/20 rounded-full blur-3xl animate-float-reverse pointer-events-none"></div>

        <div class="relative max-w-7xl mx-auto px-6 w-full">
            <div class="grid lg:grid-cols-12 gap-12 lg:gap-14 items-center">

                <!-- Left Column: Academic Heading & Core Action -->
                <div class="lg:col-span-6 space-y-7 reveal-init">

                    <!-- Academic Badge -->
                    <div class="inline-flex items-center gap-2.5 px-4 py-1.5 rounded-full bg-white border border-teal-200/90 shadow-sm">
                        <span class="w-2.5 h-2.5 rounded-full bg-teal-600 animate-pulse"></span>
                        <span class="text-xs font-black uppercase tracking-wider text-teal-800">
                            Polytechnic University (Faculty of Computing)
                        </span>
                    </div>

                    <!-- Main Headline -->
                    <h1 class="text-4xl sm:text-5xl lg:text-[3.25rem] font-black text-slate-900 leading-[1.18] tracking-tight">
                        Internship Report
                        <span class="gradient-text block mt-1">Management System</span>
                    </h1>

                    <!-- Concise Academic Summary -->
                    <p class="text-base sm:text-lg text-slate-600 leading-relaxed max-w-xl">
                        A centralized digital system connecting university students, university faculty supervisors, and company instructors for daily log tracking, weekly reflection, and academic evaluation.
                    </p>

                    <!-- CTA Buttons -->
                    <div class="flex flex-wrap items-center gap-4 pt-1">
                        <a href="login.php" class="px-8 py-4 bg-gradient-to-r from-teal-700 via-teal-800 to-slate-900 hover:from-teal-800 hover:to-black text-white text-base font-bold rounded-2xl shadow-xl shadow-teal-900/25 hover:shadow-teal-900/40 transition-all duration-300 hover:scale-105 active:scale-[0.98] cursor-pointer inline-flex items-center gap-2.5">
                            <span>Sign In</span>
                            <span class="text-lg">→</span>
                        </a>
                        <a href="#how-it-works" class="px-7 py-4 bg-white border-2 border-slate-200/90 text-slate-700 text-base font-bold rounded-2xl hover:border-teal-400 hover:text-teal-700 hover:bg-teal-50/30 shadow-xs transition-all duration-300 inline-flex items-center gap-2">
                            <span>How It Works</span>
                            <span class="text-xs">↓</span>
                        </a>
                    </div>

                    <!-- University Statistics Strip -->
                    <div class="grid grid-cols-3 gap-4 pt-4">
                        <!-- Stat 1: Students -->
                        <div class="p-4 rounded-2xl bg-white border border-slate-200/90 shadow-sm hover:shadow-md hover:border-teal-300 transition-all duration-300">
                            <span class="text-xl mb-1 block">👨‍🎓</span>
                            <span class="text-2xl sm:text-3xl font-black text-slate-900" data-target-count="<?= $stat_students ?>" data-suffix="+"><?= $stat_students ?>+</span>
                            <span class="text-[0.6875rem] font-extrabold text-slate-500 uppercase tracking-wider block mt-0.5">Students</span>
                        </div>

                        <!-- Stat 2: Companies -->
                        <div class="p-4 rounded-2xl bg-white border border-slate-200/90 shadow-sm hover:shadow-md hover:border-teal-300 transition-all duration-300">
                            <span class="text-xl mb-1 block">🏢</span>
                            <span class="text-2xl sm:text-3xl font-black text-slate-900" data-target-count="<?= $stat_companies ?>" data-suffix="+"><?= $stat_companies ?>+</span>
                            <span class="text-[0.6875rem] font-extrabold text-slate-500 uppercase tracking-wider block mt-0.5">Companies</span>
                        </div>

                        <!-- Stat 3: 100% Digital -->
                        <div class="p-4 rounded-2xl bg-white border border-slate-200/90 shadow-sm hover:shadow-md hover:border-teal-300 transition-all duration-300">
                            <span class="text-xl mb-1 block">⚡</span>
                            <span class="text-2xl sm:text-3xl font-black text-teal-700">100%</span>
                            <span class="text-[0.6875rem] font-extrabold text-slate-500 uppercase tracking-wider block mt-0.5">Paperless</span>
                        </div>
                    </div>

                </div>

                <!-- Right Column: Academic Visual & Live Badges -->
                <div class="lg:col-span-6 relative reveal-init">
                    <div class="relative rounded-3xl p-3 bg-gradient-to-br from-white via-teal-50/40 to-slate-100 border border-slate-200/80 shadow-2xl shadow-slate-900/10">
                        <div class="rounded-2xl overflow-hidden shadow-inner relative group">
                            <img src="assets/images/internship_hero_visual.jpg" alt="University Tech Lab & Student Mentoring" class="w-full h-auto object-cover rounded-2xl group-hover:scale-105 transition-transform duration-700">

                            <!-- Vignette Overlay -->
                            <div class="absolute inset-0 bg-gradient-to-t from-slate-950/70 via-transparent to-transparent pointer-events-none"></div>

                            <!-- Bottom Floating Academic Badge -->
                            <div class="absolute bottom-4 left-4 right-4 p-4 rounded-xl bg-white/95 backdrop-blur-md border border-white/90 shadow-lg flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-teal-700 to-slate-900 flex items-center justify-center text-white text-lg shadow-sm shrink-0">🎓</div>
                                    <div>
                                        <p class="text-xs font-black text-slate-900">Standardized Evaluation</p>
                                        <p class="text-[0.6875rem] text-slate-500 font-medium">Daily Logs • Weekly Reports • Faculty Grading</p>
                                    </div>
                                </div>
                                <span class="px-2.5 py-1 rounded-full bg-emerald-100 text-emerald-800 text-[0.625rem] font-black uppercase tracking-wider shrink-0">Active</span>
                            </div>
                        </div>

                        <!-- Floating Live UI Badge Top Left -->
                        <div class="absolute -top-5 -left-5 p-3.5 rounded-2xl bg-white/95 backdrop-blur-xl border border-slate-200/90 shadow-xl flex items-center gap-3 hover:scale-105 transition-transform duration-300">
                            <div class="w-9 h-9 rounded-xl bg-teal-100 text-teal-800 flex items-center justify-center text-base font-bold shrink-0">📝</div>
                            <div>
                                <p class="text-xs font-bold text-slate-900">Student Daily Logs</p>
                                <p class="text-[0.625rem] text-emerald-600 font-extrabold">✓ Real-time Tracking</p>
                            </div>
                        </div>

                        <!-- Floating Live UI Badge Bottom Right -->
                        <div class="absolute -bottom-4 -right-4 p-3.5 rounded-2xl bg-white/95 backdrop-blur-xl border border-slate-200/90 shadow-xl flex items-center gap-3 hover:scale-105 transition-transform duration-300">
                            <div class="w-9 h-9 rounded-xl bg-indigo-100 text-indigo-800 flex items-center justify-center text-base font-bold shrink-0">🏆</div>
                            <div>
                                <p class="text-xs font-bold text-slate-900">Academic Grading</p>
                                <p class="text-[0.625rem] text-indigo-700 font-extrabold">Official A–F Standard</p>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </section>

    <!-- ════ HOW IT WORKS (STANDARDIZED WORKFLOW) ════ -->
    <section id="how-it-works" class="py-24 bg-white border-t border-slate-200/70 relative overflow-hidden">
        <!-- Ambient background glow -->
        <div class="absolute -top-24 left-1/2 -translate-x-1/2 w-3/4 h-48 bg-gradient-to-r from-teal-100/40 via-blue-100/30 to-purple-100/30 blur-3xl pointer-events-none"></div>

        <div class="max-w-7xl mx-auto px-6 relative">

            <!-- Section Header -->
            <div class="text-center mb-16 reveal-init">
                <span class="inline-flex items-center gap-2 px-4 py-1.5 bg-teal-50 border border-teal-200/80 rounded-full mb-4 shadow-2xs">
                    <span class="w-2 h-2 rounded-full bg-teal-600 animate-pulse"></span>
                    <span class="text-xs font-black uppercase tracking-wider text-teal-800">Standardized Process</span>
                </span>
                <h2 class="text-3xl sm:text-4xl font-black text-slate-900 mb-4 tracking-tight">
                    How InternReport <span class="gradient-text">Works</span>
                </h2>
                <p class="text-base sm:text-lg text-slate-600 max-w-2xl mx-auto leading-relaxed">
                    A clear 4-step collaboration between students, company instructors, and university supervisors.
                </p>
            </div>

            <!-- Connected 4-Step Pipeline Grid -->
            <div class="relative">
                <!-- Desktop Connected Line -->
                <div class="hidden lg:block absolute top-1/2 -translate-y-8 left-12 right-12 h-0.5 bg-gradient-to-r from-teal-200 via-blue-200 to-indigo-200 -z-0 pointer-events-none"></div>

                <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-6 relative z-10">

                    <!-- Step 1: Student Daily Log -->
                    <div class="bg-slate-50/80 backdrop-blur-md rounded-3xl p-7 border border-slate-200/90 shadow-sm hover:shadow-xl hover:border-teal-400 hover:-translate-y-2 transition-all duration-300 flex flex-col justify-between group reveal-init">
                        <div>
                            <div class="flex items-center justify-between mb-6">
                                <div class="w-13 h-13 p-3 rounded-2xl bg-gradient-to-br from-teal-600 to-emerald-700 flex items-center justify-center text-2xl text-white shadow-md shadow-teal-600/20 group-hover:scale-110 group-hover:rotate-3 transition-transform duration-300">
                                    📝
                                </div>
                                <span class="text-xs font-black text-teal-700 bg-teal-100/60 border border-teal-200/80 px-3 py-1 rounded-full uppercase tracking-wider">
                                    Step 01
                                </span>
                            </div>
                            <span class="text-[10px] font-extrabold uppercase tracking-wider text-teal-700 block mb-1">Student</span>
                            <h3 class="text-lg font-black text-slate-900 mb-2 group-hover:text-teal-700 transition-colors">
                                Daily Log Entry
                            </h3>
                            <p class="text-xs sm:text-sm text-slate-600 leading-relaxed">
                                Students record daily tasks, working hours, and tools used during the internship.
                            </p>
                        </div>
                        <div class="mt-6 pt-4 border-t border-slate-200/80 flex items-center gap-2 text-xs font-bold text-teal-800">
                            <span class="w-1.5 h-1.5 rounded-full bg-teal-600"></span>
                            <span>Daily Work Tracking</span>
                        </div>
                    </div>

                    <!-- Step 2: Weekly Reflection -->
                    <div class="bg-slate-50/80 backdrop-blur-md rounded-3xl p-7 border border-slate-200/90 shadow-sm hover:shadow-xl hover:border-blue-400 hover:-translate-y-2 transition-all duration-300 flex flex-col justify-between group reveal-init">
                        <div>
                            <div class="flex items-center justify-between mb-6">
                                <div class="w-13 h-13 p-3 rounded-2xl bg-gradient-to-br from-blue-600 to-cyan-700 flex items-center justify-center text-2xl text-white shadow-md shadow-blue-600/20 group-hover:scale-110 group-hover:rotate-3 transition-transform duration-300">
                                    💡
                                </div>
                                <span class="text-xs font-black text-blue-700 bg-blue-100/60 border border-blue-200/80 px-3 py-1 rounded-full uppercase tracking-wider">
                                    Step 02
                                </span>
                            </div>
                            <span class="text-[10px] font-extrabold uppercase tracking-wider text-blue-700 block mb-1">Weekly Report</span>
                            <h3 class="text-lg font-black text-slate-900 mb-2 group-hover:text-blue-700 transition-colors">
                                Weekly Reflection
                            </h3>
                            <p class="text-xs sm:text-sm text-slate-600 leading-relaxed">
                                Students submit a weekly reflection report summarizing learnings and achievements.
                            </p>
                        </div>
                        <div class="mt-6 pt-4 border-t border-slate-200/80 flex items-center gap-2 text-xs font-bold text-blue-800">
                            <span class="w-1.5 h-1.5 rounded-full bg-blue-600"></span>
                            <span>Weekly Progress Summary</span>
                        </div>
                    </div>

                    <!-- Step 3: Company Instructor -->
                    <div class="bg-slate-50/80 backdrop-blur-md rounded-3xl p-7 border border-slate-200/90 shadow-sm hover:shadow-xl hover:border-purple-400 hover:-translate-y-2 transition-all duration-300 flex flex-col justify-between group reveal-init">
                        <div>
                            <div class="flex items-center justify-between mb-6">
                                <div class="w-13 h-13 p-3 rounded-2xl bg-gradient-to-br from-purple-600 to-indigo-700 flex items-center justify-center text-2xl text-white shadow-md shadow-purple-600/20 group-hover:scale-110 group-hover:rotate-3 transition-transform duration-300">
                                    👨‍🏫
                                </div>
                                <span class="text-xs font-black text-purple-700 bg-purple-100/60 border border-purple-200/80 px-3 py-1 rounded-full uppercase tracking-wider">
                                    Step 03
                                </span>
                            </div>
                            <span class="text-[10px] font-extrabold uppercase tracking-wider text-purple-700 block mb-1">Company Instructor</span>
                            <h3 class="text-lg font-black text-slate-900 mb-2 group-hover:text-purple-700 transition-colors">
                                Instructor Evaluation
                            </h3>
                            <p class="text-xs sm:text-sm text-slate-600 leading-relaxed">
                                Company instructors review weekly reports and submit feedback via a secure link.
                            </p>
                        </div>
                        <div class="mt-6 pt-4 border-t border-slate-200/80 flex items-center gap-2 text-xs font-bold text-purple-800">
                            <span class="w-1.5 h-1.5 rounded-full bg-purple-600"></span>
                            <span>Secure Evaluation Link</span>
                        </div>
                    </div>

                    <!-- Step 4: University Supervisor -->
                    <div class="bg-slate-50/80 backdrop-blur-md rounded-3xl p-7 border border-slate-200/90 shadow-sm hover:shadow-xl hover:border-emerald-400 hover:-translate-y-2 transition-all duration-300 flex flex-col justify-between group reveal-init">
                        <div>
                            <div class="flex items-center justify-between mb-6">
                                <div class="w-13 h-13 p-3 rounded-2xl bg-gradient-to-br from-emerald-600 to-teal-800 flex items-center justify-center text-2xl text-white shadow-md shadow-emerald-600/20 group-hover:scale-110 group-hover:rotate-3 transition-transform duration-300">
                                    🎓
                                </div>
                                <span class="text-xs font-black text-emerald-700 bg-emerald-100/60 border border-emerald-200/80 px-3 py-1 rounded-full uppercase tracking-wider">
                                    Step 04
                                </span>
                            </div>
                            <span class="text-[10px] font-extrabold uppercase tracking-wider text-emerald-700 block mb-1">University Supervisor</span>
                            <h3 class="text-lg font-black text-slate-900 mb-2 group-hover:text-emerald-700 transition-colors">
                                Supervisor Grading
                            </h3>
                            <p class="text-xs sm:text-sm text-slate-600 leading-relaxed">
                                University supervisors review weekly student performance, instructor feedback, and assign grades.
                            </p>
                        </div>
                        <div class="mt-6 pt-4 border-t border-slate-200/80 flex items-center gap-2 text-xs font-bold text-emerald-800">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-600"></span>
                            <span>Official Academic Grade</span>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </section>

    <!-- ════ MINIMAL ELEGANT UNIVERSITY FOOTER ════ -->
    <footer class="bg-slate-900 text-slate-400 py-10 border-t border-slate-800 mt-auto">
        <div class="max-w-7xl mx-auto px-6 flex flex-col sm:flex-row items-center justify-between gap-4 text-center sm:text-left">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-xl bg-teal-600/20 border border-teal-500/30 flex items-center justify-center text-teal-400 text-sm">🎓</div>
                <div>
                    <span class="text-sm font-bold text-white tracking-tight">InternReport</span>
                    <span class="text-xs text-slate-500 ml-2">Polytechnic University (Faculty of Computing)</span>
                </div>
            </div>
            <p class="text-xs text-slate-500">
                © <?= date('Y') ?> Polytechnic University (Faculty of Computing). All rights reserved.
            </p>
        </div>
    </footer>

    <!-- ════ INTERACTIVE ENHANCEMENTS JAVASCRIPT ════ -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // 1. Scroll Reveal Animations (IntersectionObserver)
            const reveals = document.querySelectorAll('.reveal-init');
            const observer = new IntersectionObserver((entries, obs) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        setTimeout(() => {
                            entry.target.classList.add('reveal-visible');
                        }, 50);
                        obs.unobserve(entry.target);
                    }
                });
            }, {
                threshold: 0.1,
                rootMargin: '0px 0px -20px 0px'
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
                            const duration = 900;
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

            // 3. Navbar Scroll Elevation
            const navbar = document.getElementById('main-nav');
            if (navbar) {
                window.addEventListener('scroll', () => {
                    if (window.scrollY > 20) {
                        navbar.classList.add('shadow-md', 'shadow-slate-900/5', 'bg-white/95');
                        navbar.classList.remove('bg-white/85');
                    } else {
                        navbar.classList.remove('shadow-md', 'shadow-slate-900/5', 'bg-white/95');
                        navbar.classList.add('bg-white/85');
                    }
                }, {
                    passive: true
                });
            }
        });
    </script>

</body>

</html>