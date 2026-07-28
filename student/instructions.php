<?php
require_once __DIR__ . '/../config/week_helper.php';
require_once __DIR__ . '/../auth.php';

$user_id  = $_SESSION['user_id'];
$username = $_SESSION['username'];
$role     = $_SESSION['role'];

if ($role !== 'student') {
    header('Location: ../dashboard.php');
    exit;
}

$internship_id = $user_id;
$esc_uid = $conn->real_escape_string($user_id);
$esc_iid = $conn->real_escape_string($internship_id);

$profile_r = $conn->query("SELECT sp.full_name, sp.student_roll, sp.internship_start_date, sp.internship_end_date,
    sp.company_name, sp.job_role, sp.supervisor_id, sp.instructor_name,
    u.profile_pic
    FROM student_profiles sp
    LEFT JOIN users u ON u.id = sp.user_id
    WHERE sp.user_id = {$esc_uid}");
$profile_row = $profile_r ? $profile_r->fetch_assoc() : null;
$student_name = $profile_row['full_name'] ?? $username;
$profile_pic  = $profile_row['profile_pic'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instructions - Student Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        @keyframes gradientShift { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
        .animated-bg { background: linear-gradient(-45deg, #e0e7ff, #ede9fe, #fce7f3, #dbeafe, #d1fae5); background-size: 400% 400%; animation: gradientShift 20s ease infinite; }
        .glass-sidebar { background: rgba(30, 27, 75, 0.85); backdrop-filter: blur(20px); }
        @media print { aside, header, .no-print { display: none !important; } .flex.h-screen { height: auto !important; overflow: visible !important; } main { overflow: visible !important; } body { background: white !important; } }
    </style>
</head>
<body class="animated-bg font-sans antialiased">

<div class="flex h-screen overflow-hidden">

    <!-- ─── SIDEBAR ─── -->
    <aside class="w-56 glass-sidebar flex flex-col shrink-0">
        <div class="h-14 flex items-center px-5 border-b border-white/10">
            <span class="text-sm font-black text-white tracking-tight">📋 InternReport</span>
        </div>
        <nav class="flex-1 py-4 space-y-1 px-2">
            <a href="student-dashboard.php" class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-white/60 hover:text-white hover:bg-white/10 transition">
                <span>📝</span> Dashboard
            </a>
            <a href="student-dashboard.php?section=analytics" class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-white/60 hover:text-white hover:bg-white/10 transition">
                <span>📊</span> Analytics
            </a>
            <a href="log-history.php" class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-white/60 hover:text-white hover:bg-white/10 transition">
                <span>📜</span> Log History
            </a>
            <a href="public-holiday.php" class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-white/60 hover:text-white hover:bg-white/10 transition">
                <span>📅</span> Public Holidays
            </a>
            <a href="instructions.php" class="nav-link active-nav flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-white/60 hover:text-white hover:bg-white/10 transition" data-section="instructions">
                <span>📋</span> Instructions
            </a>
            <a href="profile.php" class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-white/60 hover:text-white hover:bg-white/10 transition">
                <span>👤</span> Profile
            </a>
        </nav>
        <div class="p-3 border-t border-white/10">
            <a href="../logout.php" class="flex items-center gap-2 px-3 py-2 text-xs font-semibold text-red-400 hover:text-red-300 hover:bg-white/10 rounded-lg transition">🚪 Logout</a>
        </div>
    </aside>

    <!-- ─── MAIN ─── -->
    <div class="flex-1 flex flex-col min-h-0">

        <!-- Top Bar -->
        <header class="h-14 glass-header flex items-center justify-between px-6 shrink-0" style="background:rgba(255,255,255,0.7); backdrop-filter:blur(12px); border-bottom:1px solid rgba(226,232,240,0.6);">
            <div class="flex items-center gap-3">
                <span class="text-sm font-bold text-slate-700">📋 InternReport</span>
                <span class="w-px h-5 bg-slate-300/50"></span>
                <span class="text-xs font-semibold text-slate-500">Instructions</span>
            </div>
            <div class="flex items-center gap-3">
                <a href="student-dashboard.php" class="flex items-center gap-2 px-3 py-1.5 bg-white/40 hover:bg-white/60 border border-white/40 rounded-xl text-xs font-bold text-slate-600 transition">
                    ← Back to Dashboard
                </a>
                <div class="relative group/profile inline-block">
                    <button class="flex items-center gap-2.5 hover:bg-white/30 rounded-xl px-2 py-1.5 transition">
                        <?php if ($profile_pic): ?>
                        <img src="../uploads/avatars/<?= htmlspecialchars($profile_pic) ?>" alt="Avatar" class="w-8 h-8 rounded-full object-cover border-2 border-white/60 shadow-sm shrink-0">
                        <?php else: ?>
                        <span class="w-8 h-8 rounded-full bg-violet-100 text-violet-600 flex items-center justify-center text-xs font-bold shrink-0"><?= strtoupper(substr($student_name, 0, 1)) ?></span>
                        <?php endif; ?>
                        <span class="text-xs font-bold text-slate-600 hidden sm:inline"><?= htmlspecialchars($student_name) ?></span>
                    </button>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="flex-1 overflow-y-auto p-6 no-print">
            <div class="max-w-4xl mx-auto">

                <!-- Header -->
                <div class="mb-8">
                    <h1 class="text-2xl font-black text-slate-800 mb-1">📋 Instructions</h1>
                    <p class="text-sm text-slate-500">Follow these guidelines to complete your internship report accurately.</p>
                </div>

                <!-- Instructions Cards -->
                <div class="space-y-5">

                    <!-- 1. Daily Log -->
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 hover:shadow-md transition">
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-xl bg-blue-100 text-blue-600 flex items-center justify-center text-lg font-black shrink-0">1</div>
                            <div>
                                <h3 class="text-sm font-bold text-slate-800 mb-1">Daily Log Submission</h3>
                                <ul class="text-xs text-slate-500 space-y-1.5 leading-relaxed">
                                    <li>• Log in every working day and record your tasks.</li>
                                    <li>• Fill in the <strong>task title</strong>, <strong>task details</strong>, and <strong>tools used</strong>.</li>
                                    <li>• Record the actual start and end time of your work.</li>
                                    <li>• Mark your attendance status as <strong>Present</strong> or <strong>Absent</strong>.</li>
                                    <li>• You can edit or delete logs before your supervisor reviews them.</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- 2. Weekly Reflection -->
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 hover:shadow-md transition">
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-xl bg-indigo-100 text-indigo-600 flex items-center justify-center text-lg font-black shrink-0">2</div>
                            <div>
                                <h3 class="text-sm font-bold text-slate-800 mb-1">Weekly Reflection</h3>
                                <ul class="text-xs text-slate-500 space-y-1.5 leading-relaxed">
                                    <li>• Submit a reflection at the end of each week.</li>
                                    <li>• Answer all three questions: <strong>What</strong> was done, <strong>How</strong> it was done, and <strong>Why</strong> it was done.</li>
                                    <li>• Be specific and mention technologies, methods, and concepts used.</li>
                                    <li>• Reflections are reviewed by your supervisor and instructor.</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- 3. Supervisor Review -->
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 hover:shadow-md transition">
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center text-lg font-black shrink-0">3</div>
                            <div>
                                <h3 class="text-sm font-bold text-slate-800 mb-1">Supervisor Review Process</h3>
                                <ul class="text-xs text-slate-500 space-y-1.5 leading-relaxed">
                                    <li>• Your supervisor will review each daily log and weekly reflection.</li>
                                    <li>• Logs may be <strong>approved</strong> or <strong>rejected</strong> with feedback.</li>
                                    <li>• If rejected, update your log based on the feedback and resubmit.</li>
                                    <li>• Check notifications regularly for review status updates.</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- 4. Profile -->
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 hover:shadow-md transition">
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center text-lg font-black shrink-0">4</div>
                            <div>
                                <h3 class="text-sm font-bold text-slate-800 mb-1">Profile & Signature</h3>
                                <ul class="text-xs text-slate-500 space-y-1.5 leading-relaxed">
                                    <li>• Keep your profile information up to date.</li>
                                    <li>• Upload a professional profile picture.</li>
                                    <li>• Set up your digital signature for report exports.</li>
                                    <li>• Your signature will appear on exported HTML reports.</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- 5. Analytics -->
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 hover:shadow-md transition">
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-xl bg-purple-100 text-purple-600 flex items-center justify-center text-lg font-black shrink-0">5</div>
                            <div>
                                <h3 class="text-sm font-bold text-slate-800 mb-1">Analytics & Reports</h3>
                                <ul class="text-xs text-slate-500 space-y-1.5 leading-relaxed">
                                    <li>• View your weekly hours, attendance, and performance on the <strong>Analytics</strong> tab.</li>
                                    <li>• Export your report as <strong>HTML</strong> or <strong>CSV</strong> from the dashboard.</li>
                                    <li>• Use the Print option for a hard copy of your report.</li>
                                    <li>• Track your internship progress via the sidebar progress bar.</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Footer Note -->
                <div class="mt-8 text-center">
                    <p class="text-[11px] text-slate-400">For any issues, contact your supervisor or instructor.</p>
                </div>

            </div>
        </main>

    </div>
</div>

</body>
</html>
