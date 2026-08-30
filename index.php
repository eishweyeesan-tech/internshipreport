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
require_once __DIR__ . '/includes/academic_year_helper.php';

// Safe & stable academic year retrieval
ensure_academic_years_table($mysqli);
$current_year = get_active_academic_year_label($mysqli, '2025-2026');

$stat_students   = (int)($mysqli->query("SELECT COUNT(*) AS c FROM users WHERE role = 'student'")->fetch_assoc()['c'] ?? 0);
$stat_companies  = (int)($mysqli->query("SELECT COUNT(*) AS c FROM companies")->fetch_assoc()['c'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>InternReport — Internship Report Management System</title>
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
            background: linear-gradient(135deg, #0d9488 0%, #2563eb 50%, #7c3aed 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .academic-glow {
            box-shadow: 0 0 50px -12px rgba(13, 148, 136, 0.18);
        }

        .bento-card {
            transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            overflow: hidden;
        }

        .bento-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, transparent, currentColor, transparent);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .bento-card:hover::before {
            opacity: 1;
        }

        .bento-card:hover {
            transform: translateY(-6px);
        }

        /* Ambient spotlight gradient */
        .spotlight-bg {
            background-image: 
                radial-gradient(at 0% 0%, rgba(13, 148, 136, 0.08) 0px, transparent 50%),
                radial-gradient(at 100% 0%, rgba(37, 99, 235, 0.08) 0px, transparent 50%),
                radial-gradient(at 50% 100%, rgba(124, 58, 237, 0.05) 0px, transparent 50%);
        }

        .grid-pattern {
            background-size: 28px 28px;
            background-image: 
                linear-gradient(to right, rgba(15, 23, 42, 0.035) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(15, 23, 42, 0.035) 1px, transparent 1px);
        }

        @keyframes pulse-subtle {
            0%, 100% { transform: scale(1); opacity: 0.9; }
            50% { transform: scale(1.04); opacity: 1; }
        }

        .animate-pulse-subtle {
            animation: pulse-subtle 4s ease-in-out infinite;
        }
    </style>
</head>

<body class="bg-[#f8fafc] grid-pattern spotlight-bg font-sans antialiased text-slate-900 selection:bg-teal-600 selection:text-white min-h-screen flex flex-col justify-between">

    <!-- ════ TOP UNIVERSITY NAV ════ -->
    <header id="main-header" class="bg-white/80 backdrop-blur-xl border-b border-slate-200/80 sticky top-0 z-50 transition-all duration-300">
        <div class="max-w-6xl mx-auto px-6 h-16 flex items-center justify-between">
            <!-- University Crest & Title -->
            <a href="index.php" class="flex items-center gap-3 group">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-slate-900 via-teal-900 to-indigo-950 text-teal-400 flex items-center justify-center text-lg shadow-sm border border-slate-800/40 group-hover:scale-105 group-hover:rotate-3 transition-transform duration-300">
                    <i class="fa-solid fa-graduation-cap"></i>
                </div>
                <div class="flex flex-col">
                    <span class="text-sm sm:text-base font-black text-slate-900 tracking-tight leading-none group-hover:text-teal-700 transition-colors">InternReport</span>
                    <span class="text-[10px] font-bold text-teal-700 tracking-wider uppercase mt-0.5">University Portal</span>
                </div>
            </a>

            <!-- Academic Year Pill & Sign In -->
            <div class="flex items-center gap-3 sm:gap-4">
                <div class="flex items-center gap-2 px-3.5 py-1.5 bg-white/90 border border-slate-200/90 rounded-full text-xs font-bold text-slate-700 shadow-2xs">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span><?= htmlspecialchars($current_year) ?> Academic Year</span>
                </div>
                <a href="login.php" class="px-4 py-2 bg-slate-900 hover:bg-teal-600 active:scale-95 text-white text-xs font-bold rounded-xl transition-all duration-200 cursor-pointer flex items-center gap-1.5 shadow-sm hover:shadow-md">
                    <span>Sign In</span>
                    <i class="fa-solid fa-arrow-right text-[10px]"></i>
                </a>
            </div>
        </div>
    </header>

    <!-- ════ MAIN CREATIVE HERO & BENTO STAGE ════ -->
    <main class="max-w-6xl mx-auto px-6 py-10 sm:py-14 space-y-12 flex-1">
        
        <!-- Hero Headline & Metrics -->
        <div class="text-center max-w-3xl mx-auto space-y-5">
            <h1 class="text-3xl sm:text-5xl lg:text-6xl font-black text-slate-900 tracking-tight leading-[1.12]">
                Internship Report <br>
                <span class="univ-gradient-text">Management System</span>
            </h1>

            <p class="text-xs sm:text-sm text-slate-600 font-medium leading-relaxed max-w-md mx-auto">
                A unified university portal for daily log tracking, weekly reflection reports, and formal supervisor evaluations.
            </p>

            <!-- Creative Floating Metrics Pills -->
            <div class="flex flex-wrap items-center justify-center gap-3 pt-2 text-xs font-bold">
                <div class="flex items-center gap-2 bg-white/90 backdrop-blur-md px-4 py-2 rounded-2xl border border-slate-200/80 shadow-2xs hover:border-teal-300 transition-colors">
                    <span class="w-2 h-2 rounded-full bg-teal-500"></span>
                    <span class="text-slate-500 font-medium">Students:</span>
                    <span class="text-slate-900 font-black" id="counter-students" data-target="<?= $stat_students ?>"><?= $stat_students ?>+</span>
                </div>

                <div class="flex items-center gap-2 bg-white/90 backdrop-blur-md px-4 py-2 rounded-2xl border border-slate-200/80 shadow-2xs hover:border-blue-300 transition-colors">
                    <span class="w-2 h-2 rounded-full bg-blue-500"></span>
                    <span class="text-slate-500 font-medium">Companies:</span>
                    <span class="text-slate-900 font-black" id="counter-companies" data-target="<?= $stat_companies ?>"><?= $stat_companies ?>+</span>
                </div>

                <div class="flex items-center gap-2 bg-white/90 backdrop-blur-md px-4 py-2 rounded-2xl border border-slate-200/80 shadow-2xs hover:border-indigo-300 transition-colors">
                    <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
                    <span class="text-slate-900 font-black">100% Digital System</span>
                </div>
            </div>
        </div>

        <!-- ════ 3 CREATIVE BENTO PORTAL CARDS ════ -->
        <div class="grid md:grid-cols-3 gap-6">
            <!-- 1. Student Portal (Vibrant Indigo/Blue) -->
            <div class="bg-white/90 backdrop-blur-md rounded-3xl border border-blue-100 p-7 shadow-xs hover:shadow-xl hover:shadow-blue-500/10 bento-card text-blue-600 flex flex-col justify-between group">
                <div>
                    <div class="flex items-center justify-between mb-6">
                        <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-blue-500 to-indigo-600 text-white flex items-center justify-center text-xl font-bold shadow-md shadow-blue-500/20 group-hover:scale-110 group-hover:rotate-6 transition-transform duration-300">
                            <i class="fa-solid fa-user-graduate"></i>
                        </div>
                        <span class="px-3 py-1 bg-blue-50 border border-blue-200 text-blue-700 text-[10px] font-extrabold uppercase tracking-wider rounded-full">
                            Student Hub
                        </span>
                    </div>

                    <h2 class="text-lg font-black text-slate-900 mb-1.5 group-hover:text-blue-600 transition-colors">Student Portal</h2>
                    <p class="text-xs text-slate-500 leading-relaxed mb-6">
                        Submit daily task logs, write weekly reflection summaries, and download verified PDF reports.
                    </p>

                    <!-- Mini feature highlights -->
                    <div class="space-y-2 mb-6 pt-2 border-t border-slate-100 text-xs font-semibold text-slate-600">
                        <div class="flex items-center gap-2">
                            <i class="fa-solid fa-check text-blue-500 text-[10px]"></i>
                            <span>Daily Tasks &amp; Hours Log</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <i class="fa-solid fa-check text-blue-500 text-[10px]"></i>
                            <span>Weekly Reflections &amp; Signature</span>
                        </div>
                    </div>
                </div>

                <a href="login.php" class="w-full py-3 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 active:scale-95 text-white font-bold text-xs rounded-xl text-center shadow-sm hover:shadow-md transition flex items-center justify-center gap-2 cursor-pointer group/btn">
                    <span>Student Login</span>
                    <i class="fa-solid fa-arrow-right text-[10px] group-hover/btn:translate-x-1 transition-transform"></i>
                </a>
            </div>

            <!-- 2. Supervisor Portal (Vibrant Teal/Emerald) -->
            <div class="bg-white/90 backdrop-blur-md rounded-3xl border border-teal-100 p-7 shadow-xs hover:shadow-xl hover:shadow-teal-500/10 bento-card text-teal-600 flex flex-col justify-between group">
                <div>
                    <div class="flex items-center justify-between mb-6">
                        <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-teal-500 to-emerald-600 text-white flex items-center justify-center text-xl font-bold shadow-md shadow-teal-500/20 group-hover:scale-110 group-hover:rotate-6 transition-transform duration-300">
                            <i class="fa-solid fa-chalkboard-user"></i>
                        </div>
                        <span class="px-3 py-1 bg-teal-50 border border-teal-200 text-teal-700 text-[10px] font-extrabold uppercase tracking-wider rounded-full">
                            Faculty Hub
                        </span>
                    </div>

                    <h2 class="text-lg font-black text-slate-900 mb-1.5 group-hover:text-teal-600 transition-colors">Supervisor Portal</h2>
                    <p class="text-xs text-slate-500 leading-relaxed mb-6">
                        Review assigned students, evaluate weekly submissions, and record academic A–F grades.
                    </p>

                    <!-- Mini feature highlights -->
                    <div class="space-y-2 mb-6 pt-2 border-t border-slate-100 text-xs font-semibold text-slate-600">
                        <div class="flex items-center gap-2">
                            <i class="fa-solid fa-check text-teal-500 text-[10px]"></i>
                            <span>Real-Time Attendance Monitoring</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <i class="fa-solid fa-check text-teal-500 text-[10px]"></i>
                            <span>Standardized A–F Grade Rubric</span>
                        </div>
                    </div>
                </div>

                <a href="login.php" class="w-full py-3 bg-gradient-to-r from-teal-600 to-emerald-600 hover:from-teal-700 hover:to-emerald-700 active:scale-95 text-white font-bold text-xs rounded-xl text-center shadow-sm hover:shadow-md transition flex items-center justify-center gap-2 cursor-pointer group/btn">
                    <span>Supervisor Login</span>
                    <i class="fa-solid fa-arrow-right text-[10px] group-hover/btn:translate-x-1 transition-transform"></i>
                </a>
            </div>

            <!-- 3. Admin Portal (Vibrant Slate/Violet) -->
            <div class="bg-white/90 backdrop-blur-md rounded-3xl border border-slate-200 p-7 shadow-xs hover:shadow-xl hover:shadow-slate-500/10 bento-card text-slate-900 flex flex-col justify-between group">
                <div>
                    <div class="flex items-center justify-between mb-6">
                        <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-slate-800 to-slate-950 text-white flex items-center justify-center text-xl font-bold shadow-md shadow-slate-900/20 group-hover:scale-110 group-hover:rotate-6 transition-transform duration-300">
                            <i class="fa-solid fa-shield-halved"></i>
                        </div>
                        <span class="px-3 py-1 bg-slate-100 border border-slate-200 text-slate-700 text-[10px] font-extrabold uppercase tracking-wider rounded-full">
                            Admin Hub
                        </span>
                    </div>

                    <h2 class="text-lg font-black text-slate-900 mb-1.5 group-hover:text-slate-950 transition-colors">Department Admin</h2>
                    <p class="text-xs text-slate-500 leading-relaxed mb-6">
                        Manage user accounts, academic batch years, partner companies, and supervisor assignments.
                    </p>

                    <!-- Mini feature highlights -->
                    <div class="space-y-2 mb-6 pt-2 border-t border-slate-100 text-xs font-semibold text-slate-600">
                        <div class="flex items-center gap-2">
                            <i class="fa-solid fa-check text-slate-700 text-[10px]"></i>
                            <span>Academic Batch Governance</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <i class="fa-solid fa-check text-slate-700 text-[10px]"></i>
                            <span>Role Assignments &amp; Audit Logs</span>
                        </div>
                    </div>
                </div>

                <a href="login.php" class="w-full py-3 bg-slate-900 hover:bg-slate-800 active:scale-95 text-white font-bold text-xs rounded-xl text-center shadow-sm hover:shadow-md transition flex items-center justify-center gap-2 cursor-pointer group/btn">
                    <span>Admin Login</span>
                    <i class="fa-solid fa-arrow-right text-[10px] group-hover/btn:translate-x-1 transition-transform"></i>
                </a>
            </div>
        </div>

        <!-- ════ CREATIVE 3-STEP PIPELINE BAR ════ -->
        <div class="bg-white/80 backdrop-blur-md rounded-3xl border border-slate-200/90 p-6 sm:p-8 shadow-xs">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-slate-100 pb-5 mb-6">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-xl bg-teal-50 text-teal-700 flex items-center justify-center font-bold text-xs border border-teal-200/60">
                        <i class="fa-solid fa-bolt"></i>
                    </div>
                    <div>
                        <h3 class="text-sm font-black text-slate-900 tracking-tight">Evaluation Process</h3>
                        <p class="text-[11px] text-slate-400 font-medium">Standardized 3-Party Verification</p>
                    </div>
                </div>
                <div class="flex items-center gap-1.5 text-emerald-600 text-xs font-bold bg-emerald-50 px-3 py-1 rounded-full border border-emerald-200/60 self-start sm:self-auto">
                    <i class="fa-solid fa-shield-check text-emerald-500"></i>
                    <span>Verified Academic Workflow</span>
                </div>
            </div>

            <div class="grid sm:grid-cols-3 gap-5">
                <!-- Step 1 -->
                <div class="p-5 bg-gradient-to-b from-slate-50 to-white rounded-2xl border border-slate-200/70 hover:border-teal-300 transition-all duration-200">
                    <div class="w-8 h-8 rounded-xl bg-teal-100 text-teal-800 flex items-center justify-center text-xs font-black mb-3">
                        1
                    </div>
                    <h4 class="text-xs font-bold text-slate-900 mb-1">Daily Task Recording</h4>
                    <p class="text-[11px] text-slate-500 leading-relaxed">
                        Students document daily tasks, working hours, and tools used with automatic duration calculation.
                    </p>
                </div>

                <!-- Step 2 -->
                <div class="p-5 bg-gradient-to-b from-slate-50 to-white rounded-2xl border border-slate-200/70 hover:border-blue-300 transition-all duration-200">
                    <div class="w-8 h-8 rounded-xl bg-blue-100 text-blue-800 flex items-center justify-center text-xs font-black mb-3">
                        2
                    </div>
                    <h4 class="text-xs font-bold text-slate-900 mb-1">Company Instructor Review</h4>
                    <p class="text-[11px] text-slate-500 leading-relaxed">
                        Company instructors review weekly reports and submit digital sign-offs via secure 1-click Magic Links.
                    </p>
                </div>

                <!-- Step 3 -->
                <div class="p-5 bg-gradient-to-b from-slate-50 to-white rounded-2xl border border-slate-200/70 hover:border-indigo-300 transition-all duration-200">
                    <div class="w-8 h-8 rounded-xl bg-indigo-100 text-indigo-800 flex items-center justify-center text-xs font-black mb-3">
                        3
                    </div>
                    <h4 class="text-xs font-bold text-slate-900 mb-1">University Supervisor Grade</h4>
                    <p class="text-[11px] text-slate-500 leading-relaxed">
                        University faculty supervisors assess weekly progress and assign formal A–F academic grades.
                    </p>
                </div>
            </div>
        </div>

    </main>

    <!-- ════ ACADEMIC FOOTER ════ -->
    <footer class="bg-white border-t border-slate-200 py-6 text-slate-500 text-xs">
        <div class="max-w-6xl mx-auto px-6 flex flex-col sm:flex-row items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <i class="fa-solid fa-graduation-cap text-teal-700"></i>
                <span class="font-bold text-slate-800">InternReport</span>
                <span class="text-slate-400">• University Internship Management System</span>
            </div>
            <div class="flex items-center gap-4 text-slate-400">
                <span><?= htmlspecialchars($current_year) ?> Academic Year</span>
                <span>•</span>
                <a href="login.php" class="text-teal-700 hover:underline font-semibold">Sign In</a>
            </div>
        </div>
    </footer>

    <!-- ════ DYNAMIC MICRO-INTERACTIONS SCRIPT ════ -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Live Smooth Number Counter Animation
            const counters = [
                document.getElementById('counter-students'),
                document.getElementById('counter-companies')
            ];

            counters.forEach(el => {
                if (!el) return;
                const target = parseInt(el.getAttribute('data-target'), 10);
                if (!isNaN(target) && target > 0) {
                    let current = 0;
                    const duration = 1000;
                    const stepTime = 30;
                    const increment = target / (duration / stepTime);
                    const timer = setInterval(() => {
                        current += increment;
                        if (current >= target) {
                            el.textContent = target + '+';
                            clearInterval(timer);
                        } else {
                            el.textContent = Math.floor(current) + '+';
                        }
                    }, stepTime);
                }
            });

            // Navbar elevation on scroll
            const header = document.getElementById('main-header');
            window.addEventListener('scroll', () => {
                if (!header) return;
                if (window.scrollY > 15) {
                    header.classList.add('shadow-md', 'shadow-slate-900/5', 'bg-white/95');
                    header.classList.remove('bg-white/80');
                } else {
                    header.classList.remove('shadow-md', 'shadow-slate-900/5', 'bg-white/95');
                    header.classList.add('bg-white/80');
                }
            }, { passive: true });
        });
    </script>

</body>

</html>