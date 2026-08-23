<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/week_helper.php';
require_once __DIR__ . '/../includes/ui_helpers.php';

$user_id  = (int) $_SESSION['user_id'];
$username = $_SESSION['username'];

$internship_id = $user_id;

$db = $mysqli ?? $conn;

$profile_stmt = $db->prepare("SELECT sp.full_name, sp.student_roll, sp.internship_start_date, sp.internship_end_date,
    sp.company_name, sp.job_role, sp.supervisor_id, sp.instructor_name,
    u.profile_pic
    FROM student_profiles sp
    LEFT JOIN users u ON u.id = sp.user_id
    WHERE sp.user_id = ?");
$profile_stmt->bind_param("i", $user_id);
$profile_stmt->execute();
$res = $profile_stmt->get_result();
$profile_row = $res ? $res->fetch_assoc() : null;
$student_name = (($profile_row['full_name'] ?? '') ?: $username);
$profile_pic  = $profile_row['profile_pic'] ?? null;
$intern_start = $profile_row['internship_start_date'] ?? null;
$intern_end   = $profile_row['internship_end_date'] ?? null;

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

$progress_weeks_completed = 0;
$progress_total_weeks = count($weeks);
if (!empty($weeks)) {
    $wc_stmt = $db->prepare("SELECT COUNT(*) FROM daily_logs WHERE internship_id = ? AND log_date BETWEEN ? AND ?");
    foreach ($weeks as $wn => $wr) {
        $wc_stmt->bind_param("iss", $internship_id, $wr['start'], $wr['end']);
        $wc_stmt->execute();
        $res = $wc_stmt->get_result();
        $wc_row = $res ? $res->fetch_row() : null;
        if ((int) ($wc_row[0] ?? 0) > 0) {
            $progress_weeks_completed++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>အသုံးပြုနည်း လမ်းညွှန်ချက်များ - Instructions | InternReport</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontSize: {
                        'micro': '0.5rem',
                        'caption': '0.6875rem',
                        'label': '0.8125rem',
                        'subtitle': '0.9375rem',
                    },
                }
            }
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        html {
            scrollbar-gutter: stable;
            overflow-y: scroll;
        }

        body {
            font-family: 'Inter', sans-serif;
        }

        .glass-sidebar {
            background: #005f73;
            border-right: 1px solid rgba(15, 118, 110, 0.4);
        }

        .glass-sidebar nav {
            scrollbar-width: thin;
            scrollbar-color: rgba(255, 255, 255, 0.15) transparent;
        }

        .glass-sidebar nav::-webkit-scrollbar {
            width: 4px;
        }

        .glass-sidebar nav::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.15);
            border-radius: 4px;
        }

        .nav-link {
            color: #ccfbf1;
            font-weight: 500;
        }

        .nav-link:hover {
            color: #fff;
            background: rgba(15, 118, 110, 0.6);
        }

        .active-nav {
            background: #0a9396;
            color: #fff;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(10, 147, 150, 0.3);
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
</head>

<body class="bg-slate-100 font-sans antialiased">

    <div class="flex h-screen overflow-hidden">

        <!-- ─── SIDEBAR BACKDROP (MOBILE) ─── -->
        <div id="studentSidebarBackdrop" onclick="toggleStudentSidebar()" class="hidden fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40 lg:hidden print:hidden"></div>

        <!-- ─── SIDEBAR ─── -->
        <aside id="studentSidebar" class="w-64 fixed inset-y-0 left-0 z-50 transform -translate-x-full lg:translate-x-0 lg:static lg:z-auto transition-transform duration-200 ease-in-out glass-sidebar flex flex-col shrink-0 text-white shadow-xl print:hidden">
            <div class="h-16 flex items-center justify-between px-5 border-b border-white/10 shrink-0">
                <span class="font-black text-white tracking-tight text-lg">InternReport</span>
                <button type="button" onclick="toggleStudentSidebar()" class="lg:hidden text-teal-200 hover:text-white p-1 rounded-lg transition" aria-label="Close sidebar">✕</button>
            </div>
            <nav class="flex-1 min-h-0 py-4 space-y-1 px-3 overflow-y-auto">
                <a href="student-dashboard.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
                    </svg> Dashboard
                </a>
                <a href="log-history.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg> Log History
                </a>
                <a href="instructions.php" class="nav-link active-nav flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200" data-section="instructions">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253z" />
                    </svg> Instructions
                </a>
                <a href="profile.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg> Profile
                </a>
            </nav>
            <div class="p-3 border-t border-white/10">
                <a href="../logout.php" class="flex items-center gap-3 px-3 py-2.5 text-subtitle leading-relaxed font-semibold text-red-400 hover:text-red-300 hover:bg-white/10 rounded-lg transition-colors duration-200">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                    </svg> Logout
                </a>
            </div>
        </aside>

        <!-- ─── MAIN ─── -->
        <div class="flex-1 flex flex-col min-h-0">

            <!-- Top Bar -->
            <?php $pageTitle = 'Instructions';
            $show_back_link = true;
            include '../includes/student-topbar.php'; ?>

            <!-- Content -->
            <main class="flex-1 overflow-y-auto p-6 md:p-8 no-print">
                <div class="max-w-5xl mx-auto w-full space-y-6">

                    <!-- Header Banner -->
                    <div class="bg-gradient-to-r from-teal-800 to-cyan-900 rounded-3xl p-6 sm:p-8 text-white shadow-md relative overflow-hidden">
                        <div class="relative z-10">
                            <div class="inline-flex items-center gap-2 px-3 py-1 bg-white/15 backdrop-blur-md rounded-full text-xs font-semibold text-teal-100 mb-3 border border-white/10">
                                <span>📖</span> Student Internship Guide &amp; Workflow
                            </div>
                            <h1 class="text-lg sm:text-2xl font-black tracking-tight">အလုပ်သင်မှတ်တမ်း စနစ်အသုံးပြုနည်း လမ်းညွှန်ချက်များ</h1>
                            <p class="text-xs sm:text-sm text-teal-100/90 mt-2 max-w-2xl leading-relaxed">
                                သင့် Internship ကာလတစ်လျှောက် နေ့စဉ်လုပ်ငန်းမှတ်တမ်း (Daily Logs)၊ အပတ်စဉ်သုံးသပ်ချက် (Weekly Reflection) နှင့် တရားဝင် အစီရင်ခံစာ (Official Report) များကို စနစ်တကျ မှတ်တမ်းတင်နိုင်ရန် အောက်ပါလုပ်ငန်းစဉ်များကို လိုက်နာဆောင်ရွက်ပါ။
                            </p>
                        </div>
                        <div class="absolute -right-6 -bottom-8 opacity-10 pointer-events-none text-9xl">📑</div>
                    </div>

                    <!-- Steps Timeline / Cards -->
                    <div class="space-y-5">

                        <!-- 1. Profile & Signature Setup -->
                        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 hover:shadow-md transition">
                            <div class="flex items-start gap-4">
                                <div class="w-10 h-10 rounded-2xl bg-amber-50 text-amber-600 border border-amber-200 flex items-center justify-center text-base font-black shrink-0">1</div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between flex-wrap gap-2 mb-2">
                                        <h2 class="text-sm font-bold text-slate-800 flex items-center gap-2">
                                            <span>👤</span> Profile နှင့် Digital Signature ကြိုတင်ပြင်ဆင်ခြင်း
                                        </h2>
                                        <span class="text-[11px] font-semibold text-amber-700 bg-amber-50 px-2.5 py-0.5 rounded-full border border-amber-200">First Step / ပထမအဆင့်</span>
                                    </div>
                                    <ul class="text-xs text-slate-600 space-y-2 leading-relaxed">
                                        <li class="flex items-start gap-2">
                                            <span class="text-teal-600 font-bold">•</span>
                                            <span><strong>Profile အချက်အလက်များ:</strong> Roll No၊ Major၊ Company Name၊ Job Role၊ Company Instructor အမည်နှင့် Email တို့ကို <a href="profile.php" class="text-indigo-600 underline font-semibold hover:text-indigo-800">Profile စာမျက်နှာ</a> တွင် ပြည့်စုံစွာ ဖြည့်သွင်းထားပါ။</span>
                                        </li>
                                        <li class="flex items-start gap-2">
                                            <span class="text-teal-600 font-bold">•</span>
                                            <span><strong>Digital Signature:</strong> အပတ်စဉ် Report တင်သွင်းရာတွင် အသုံးပြုရန် မိမိ၏ လက်မှတ်ကို <strong>Type (စာလုံးပုံစံ)</strong> သို့မဟုတ် <strong>Upload / Draw (လက်ရေးပုံစံ)</strong> ဖြင့် Profile တွင် ကြိုတင် သိမ်းဆည်းထားနိုင်ပါသည်။</span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <!-- 2. Daily Log Entry -->
                        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 hover:shadow-md transition">
                            <div class="flex items-start gap-4">
                                <div class="w-10 h-10 rounded-2xl bg-blue-50 text-blue-600 border border-blue-200 flex items-center justify-center text-base font-black shrink-0">2</div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between flex-wrap gap-2 mb-2">
                                        <h2 class="text-sm font-bold text-slate-800 flex items-center gap-2">
                                            <span>📅</span> နေ့စဉ် လုပ်ငန်းမှတ်တမ်း ရေးသွင်းခြင်း (Daily Work Log)
                                        </h2>
                                        <span class="text-[11px] font-semibold text-blue-700 bg-blue-50 px-2.5 py-0.5 rounded-full border border-blue-200">Daily Task / နေ့စဉ်လုပ်ဆောင်ရန်</span>
                                    </div>
                                    <ul class="text-xs text-slate-600 space-y-2 leading-relaxed">
                                        <li class="flex items-start gap-2">
                                            <span class="text-teal-600 font-bold">•</span>
                                            <span><strong>Attendance Status:</strong> အလုပ်ဆင်းသည့်နေ့တိုင်း တက်ရောက်မှုအခြေအနေ (<strong>Present / Absent / Public Holiday</strong>) ကို မှန်ကန်စွာ ရွေးချယ်ပါ။</span>
                                        </li>
                                        <li class="flex items-start gap-2">
                                            <span class="text-teal-600 font-bold">•</span>
                                            <span><strong>Present ဖြစ်ပါက:</strong> စတင်ချိန်/ပြီးဆုံးချိန် (Start/End Time)၊ <strong>ဆောင်ရွက်မည့်လုပ်ငန်း (Task Title)</strong>၊ <strong>အမှန်တကယ် လုပ်ဆောင်ခဲ့သော လုပ်ငန်းစဉ်များ (Actual Tasks Performed)</strong>၊ <strong>အသုံးပြုသော ကိရိယာ/နည်းပညာများ (Tools Used)</strong> နှင့် <strong>လေ့လာသိရှိသော အသိပညာ (Learnt Skills)</strong> တို့ကို ရှင်းလင်းစွာ ဖြည့်သွင်းပါ။</span>
                                        </li>
                                        <li class="flex items-start gap-2">
                                            <span class="text-teal-600 font-bold">•</span>
                                            <span><strong>Absent / Leave ဖြစ်ပါက:</strong> ခွင့်ယူ/ပျက်ကွက်ရသည့် အကြောင်းအရင်း (Reason for Absence) ကို ထည့်သွင်းပါ။ (အများပြည်သူရုံးပိတ်ရက်ဖြစ်ပါက Public Holiday အကြောင်းအရာ ဖော်ပြနိုင်ပါသည်)။</span>
                                        </li>
                                        <li class="flex items-start gap-2">
                                            <span class="text-teal-600 font-bold">•</span>
                                            <span><strong>ပြင်ဆင်ခွင့် (Edit Mode):</strong> အပတ်စဉ် Reflection မတင်သွင်းရသေးမီ အချိန်အထိ နေ့စဉ် Log များကို Edit ပြန်လည် ပြင်ဆင်နိုင်ပါသည်။</span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <!-- 3. Weekly Reflection & Signature Submission -->
                        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 hover:shadow-md transition">
                            <div class="flex items-start gap-4">
                                <div class="w-10 h-10 rounded-2xl bg-indigo-50 text-indigo-600 border border-indigo-200 flex items-center justify-center text-base font-black shrink-0">3</div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between flex-wrap gap-2 mb-2">
                                        <h2 class="text-sm font-bold text-slate-800 flex items-center gap-2">
                                            <span>💡</span> အပတ်စဉ် သုံးသပ်ချက်နှင့် လက်မှတ်ရေးထိုး တင်သွင်းခြင်း (Weekly Reflection)
                                        </h2>
                                        <span class="text-[11px] font-semibold text-indigo-700 bg-indigo-50 px-2.5 py-0.5 rounded-full border border-indigo-200">Weekly Task / အပတ်စဉ်လုပ်ဆောင်ရန်</span>
                                    </div>
                                    <ul class="text-xs text-slate-600 space-y-2 leading-relaxed">
                                        <li class="flex items-start gap-2">
                                            <span class="text-teal-600 font-bold">•</span>
                                            <span><strong>Reflection မေးခွန်း ၃ ခု:</strong> အပတ်စဉ်ပြီးဆုံးတိုင်း မေးခွန်း ၃ ခုဖြစ်သော <strong>1. What was done? (ဘာတွေလုပ်ဆောင်ခဲ့သလဲ)</strong>၊ <strong>2. How was it done? (ဘယ်လိုနည်းစနစ်များဖြင့် ဆောင်ရွက်ခဲ့သလဲ)</strong> နှင့် <strong>3. Why was it done? (ဘာကြောင့် ဤနည်းလမ်းကို ရွေးချယ်ဆောင်ရွက်ခဲ့သလဲ)</strong> တို့ကို ပြည့်စုံစွာ ဖြေဆိုရပါမည်။</span>
                                        </li>
                                        <li class="flex items-start gap-2">
                                            <span class="text-teal-600 font-bold">•</span>
                                            <span><strong>ကျောင်းသားလက်မှတ် (Student Digital Signature):</strong> Weekly Reflection အောက်ခြေတွင် မိမိ၏ Signature ကို ရေးထိုးပြီးမှသာ အပတ်စဉ် အစီရင်ခံစာကို Submit ပြုလုပ်နိုင်မည် ဖြစ်ပါသည်။</span>
                                        </li>
                                        <li class="flex items-start gap-2">
                                            <span class="text-teal-600 font-bold">•</span>
                                            <span><strong>Locked (Read-Only Mode):</strong> Reflection တင်သွင်းပြီးပါက ထိုအပတ်အတွင်းရှိ Daily Logs များနှင့် Reflection အချက်အလက်များသည် <strong>Locked (Read-Only)</strong> ဖြစ်သွားမည် ဖြစ်ပါသည်။</span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <!-- 4. Review & Evaluation by Instructors & Supervisors -->
                        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 hover:shadow-md transition">
                            <div class="flex items-start gap-4">
                                <div class="w-10 h-10 rounded-2xl bg-emerald-50 text-emerald-600 border border-emerald-200 flex items-center justify-center text-base font-black shrink-0">4</div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between flex-wrap gap-2 mb-2">
                                        <h2 class="text-sm font-bold text-slate-800 flex items-center gap-2">
                                            <span>✍️</span> စစ်ဆေးအကဲဖြတ်ခြင်း လုပ်ငန်းစဉ် (Review &amp; Evaluation Process)
                                        </h2>
                                        <span class="text-[11px] font-semibold text-emerald-700 bg-emerald-50 px-2.5 py-0.5 rounded-full border border-emerald-200">Evaluation / အကဲဖြတ်ချက်</span>
                                    </div>
                                    <ul class="text-xs text-slate-600 space-y-2 leading-relaxed">
                                        <li class="flex items-start gap-2">
                                            <span class="text-teal-600 font-bold">•</span>
                                            <span><strong>Company Instructor (ကုမ္ပဏီကြီးကြပ်သူ):</strong> ကျောင်းသားတင်သွင်းထားသော Reflection ကို <strong>Magic Link</strong> သို့မဟုတ် Account မှတစ်ဆင့် ဝင်ရောက်စစ်ဆေးပြီး Grade၊ မှတ်ချက်များနှင့် အတည်ပြုလက်မှတ် (Digital Signature) ရေးထိုးပေးပါမည်။ (ပြင်ဆင်ရန်လိုအပ်ပါက Revision Note ပေးပို့ပါမည်)။</span>
                                        </li>
                                        <li class="flex items-start gap-2">
                                            <span class="text-teal-600 font-bold">•</span>
                                            <span><strong>CU Supervisor (တက္ကသိုလ်ကြီးကြပ်ဆရာ/မ):</strong> တက္ကသိုလ်မှ တာဝန်ခံဆရာ/မသည် ကျောင်းသား၏ အပတ်စဉ်စွမ်းဆောင်ရည်ကို <strong>Weekly Grade (A, B, C, D, F)</strong> နှင့် သုံးသပ်အကြံပြုချက် Feedback များ ပေးအပ်မည်ဖြစ်ပါသည်။</span>
                                        </li>
                                        <li class="flex items-start gap-2">
                                            <span class="text-teal-600 font-bold">•</span>
                                            <span><strong>အခြေအနေစစ်ဆေးခြင်း:</strong> ဆရာများ၏ Evaluation၊ မှတ်ချက်များနှင့် Grade များကို <a href="student-dashboard.php" class="text-indigo-600 underline font-semibold hover:text-indigo-800">Dashboard</a> နှင့် <a href="log-history.php" class="text-indigo-600 underline font-semibold hover:text-indigo-800">Log History</a> တွင် ချက်ချင်း စစ်ဆေးနိုင်ပါသည်။</span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <!-- 5. Official Report & Printing -->
                        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 hover:shadow-md transition">
                            <div class="flex items-start gap-4">
                                <div class="w-10 h-10 rounded-2xl bg-purple-50 text-purple-600 border border-purple-200 flex items-center justify-center text-base font-black shrink-0">5</div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between flex-wrap gap-2 mb-2">
                                        <h2 class="text-sm font-bold text-slate-800 flex items-center gap-2">
                                            <span>🖨️</span> တရားဝင် အစီရင်ခံစာ ထုတ်ယူခြင်း (Official Print &amp; PDF Export)
                                        </h2>
                                        <span class="text-[11px] font-semibold text-purple-700 bg-purple-50 px-2.5 py-0.5 rounded-full border border-purple-200">Official Report / စာရွက်စာတမ်း</span>
                                    </div>
                                    <ul class="text-xs text-slate-600 space-y-2 leading-relaxed">
                                        <li class="flex items-start gap-2">
                                            <span class="text-teal-600 font-bold">•</span>
                                            <span><strong>Print Report ရယူခြင်း:</strong> <a href="log-history.php" class="text-indigo-600 underline font-semibold hover:text-indigo-800">Log History</a> စာမျက်နှာရှိ <strong>Print</strong> ခလုတ်ကို နှိပ်၍ သက်ဆိုင်ရာ အပတ်စဉ်အလိုက် (သို့မဟုတ် All Weeks အားလုံးအတွက်) တရားဝင် အစီရင်ခံစာ စာမျက်နှာကို ဖွင့်လှစ်နိုင်ပါသည်။</span>
                                        </li>
                                        <li class="flex items-start gap-2">
                                            <span class="text-teal-600 font-bold">•</span>
                                            <span><strong>အပြည့်အစုံ ပါဝင်မှု:</strong> Official Report တွင် တရားဝင် Letterhead၊ ကျောင်းသားနှင့် ကြီးကြပ်သူအချက်အလက်များ၊ Daily Log Table၊ Weekly Reflection၊ Company Instructor နှင့် University Supervisor တို့၏ Feedback များအပြင် ၃ ဦးစလုံး၏ Formal Signatures များ အပြည့်အစုံ ပါဝင်ပါသည်။</span>
                                        </li>
                                        <li class="flex items-start gap-2">
                                            <span class="text-teal-600 font-bold">•</span>
                                            <span><strong>Hardcopy & PDF:</strong> အစီရင်ခံစာ စာမျက်နှာရှိ <strong>Print / Save PDF</strong> ခလုတ်ကို အသုံးပြု၍ A4 Format ဖြင့် Hardcopy ထုတ်ယူခြင်း သို့မဟုတ် PDF အဖြစ် ကွန်ပျူတာထဲသို့ တိုက်ရိုက် Save ပြုလုပ်နိုင်ပါသည်။</span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- Helpful Notice Banner -->
                    <div class="bg-teal-50 border border-teal-200/80 rounded-2xl p-5 flex items-start gap-3.5">
                        <div class="w-8 h-8 rounded-xl bg-teal-600 text-white flex items-center justify-center shrink-0 font-bold text-sm">💡</div>
                        <div class="text-xs text-teal-900 leading-relaxed">
                            <p class="font-bold text-teal-950 mb-1">အကြံပြုချက် (Helpful Tip):</p>
                            <p>Daily Logs များကို အလုပ်ပြီးဆုံးသည့်နေ့တိုင်း ချက်ချင်း မှတ်တမ်းတင်ခြင်းက အပတ်စဉ် Reflection ရေးသားရာတွင် လွယ်ကူစေပြီး အစီရင်ခံစာများ တိကျပြည့်စုံစေပါသည်။ စနစ်အသုံးပြုရာတွင် အခက်အခဲတစ်စုံတစ်ရာရှိပါက မိမိ၏ CU Supervisor သို့မဟုတ် Company Instructor ထံသို့ ဆက်သွယ်မေးမြန်းနိုင်ပါသည်။</p>
                        </div>
                    </div>

                    <!-- Footer Note -->
                    <div class="pt-4 text-center">
                        <p class="text-caption text-slate-400">Internship Reporting &amp; Management System • University of Computer Studies</p>
                    </div>

                </div>
            </main>

        </div>
    </div>

</body>

</html>