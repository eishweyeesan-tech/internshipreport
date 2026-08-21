<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/week_helper.php';
require_once __DIR__ . '/../includes/ui_helpers.php';

$user_id  = (int) $_SESSION['user_id'];
$username = $_SESSION['username'];

$internship_id = $user_id;

$db = $mysqli ?? $conn;

$profile_stmt = $db->prepare("SELECT sp.student_roll, sp.internship_start_date, sp.internship_end_date,
    COALESCE(c.company_name, '') AS company_name, sp.job_role, sp.supervisor_id,
    u.username, u.profile_pic
    FROM student_profiles sp
    LEFT JOIN users u ON u.id = sp.user_id
    LEFT JOIN companies c ON c.id = sp.company_id
    WHERE sp.user_id = ?");
$profile_stmt->bind_param("i", $user_id);
$profile_stmt->execute();
$res = $profile_stmt->get_result();
$profile_row = $res ? $res->fetch_assoc() : null;
$student_name = (($profile_row['username'] ?? '') ?: $username);
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
    $wc_stmt = $db->prepare("SELECT COUNT(*) FROM daily_logs WHERE student_id = ? AND log_date BETWEEN ? AND ?");
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
    <title>သင်ကြားရေး - ကျောင်းသား Dashboard</title>
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
        html { scrollbar-gutter: stable; overflow-y: scroll; }
        body { font-family: 'Inter', sans-serif; }
        .glass-sidebar { background: #005f73; border-right: 1px solid rgba(15, 118, 110, 0.4); }
        .glass-sidebar nav { scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.15) transparent; }
        .glass-sidebar nav::-webkit-scrollbar { width: 4px; }
        .glass-sidebar nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.15); border-radius: 4px; }
        .nav-link { color: #ccfbf1; font-weight: 500; }
        .nav-link:hover { color: #fff; background: rgba(15, 118, 110, 0.6); }
        .active-nav { background: #0a9396; color: #fff; font-weight: 600; box-shadow: 0 4px 12px rgba(10, 147, 150, 0.3); }
        @media print { aside, header, .no-print { display: none !important; } .flex.h-screen { height: auto !important; overflow: visible !important; } main { overflow: visible !important; } body { background: white !important; } }
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
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg> Dashboard
            </a>
            <a href="notifications.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg> Notifications
            </a>
            <a href="log-history.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 01-2 2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg> Log History
            </a>
            <a href="instructions.php" class="nav-link active-nav flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200" data-section="instructions">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253z"/></svg> Instructions
            </a>
            <a href="profile.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg> Profile
            </a>
        </nav>
        <div class="p-3 border-t border-white/10">
            <a href="../logout.php" class="flex items-center gap-3 px-3 py-2.5 text-subtitle leading-relaxed font-semibold text-red-400 hover:text-red-300 hover:bg-white/10 rounded-lg transition-colors duration-200">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg> Logout
            </a>
        </div>
    </aside>

    <!-- ─── MAIN ─── -->
    <div class="flex-1 flex flex-col min-h-0">

        <!-- Top Bar -->
        <?php $pageTitle = 'သင်ကြားရေး'; $show_back_link = true; include '../includes/student-topbar.php'; ?>

        <!-- Content -->
        <main class="flex-1 overflow-y-auto p-6 md:p-8 no-print">
            <div class="max-w-7xl mx-auto w-full">

                <!-- Header -->
                <div class="mb-6">
                    <p class="text-xs text-gray-400">သင့်internship report ကို တိကျစွာပြီးမြောက်အောင် အောက်ပါ လမ်းညွှန်ချက်များကို လိုက်နာပါ။</p>
                </div>

                <!-- Instructions Cards -->
                <div class="space-y-5">

                    <!-- 1. Daily Log -->
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 hover:shadow-md transition">
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-xl bg-blue-100 text-blue-600 flex items-center justify-center text-lg font-black shrink-0">1</div>
                            <div>
                                <h3 class="text-sm font-bold text-slate-800 mb-1">နေ့စဉ် Log တင်ခြင်း</h3>
                                <ul class="text-xs text-slate-500 space-y-1.5 leading-relaxed">
                                    <li>• အလုပ်လုပ်သည့်နေ့တိုင်း Log in ဝင်ပြီး သင့်အလုပ်များကို မှတ်တမ်းတင်ပါ။</li>
                                    <li>• <strong>အလုပ်ခေါင်းစဉ်</strong>၊ <strong>အလုပ်အသေးစိတ်</strong>နှင့် <strong>အသုံးပြုသည့်ကိရိယာများ</strong>ကို ဖြည့်ပါ။</li>
                                    <li>• သင့်အလုပ်၏ အစပိုင်းနှင့် အဆုံးအချိန်ကို မှတ်တမ်းတင်ပါ။</li>
                                    <li>• သင့်တက်ရောက်မှုအခြေအနေကို <strong>တက်ရောက်</strong> သို့မဟုတ် <strong>ပျက်ကွက်</strong> ဟု မှတ်သားပါ။</li>
                                    <li>• အပတ်စဉ် Reflection တင်သွင်းပြီးပါက ထိုအပတ်အတွင်းရှိ Log များသည် <strong>Read-Only</strong> ဖြစ်သွားမည်ဖြစ်ပါသည်။</li>
                                    <li>• အပတ်စဉ် Reflection မတင်မီအထိ Log များကို ပြင်ဆင်ခြင်း သို့မဟုတ် ဖျက်ခြင်း ပြုနိုင်ပါသည်။</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- 2. Weekly Reflection -->
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 hover:shadow-md transition">
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-xl bg-indigo-100 text-indigo-600 flex items-center justify-center text-lg font-black shrink-0">2</div>
                            <div>
                                <h3 class="text-sm font-bold text-slate-800 mb-1">အပတ်စဉ် Reflection နှင့် လက်မှတ်ထိုးခြင်း</h3>
                                <ul class="text-xs text-slate-500 space-y-1.5 leading-relaxed">
                                    <li>• အပတ်တိုင်း၏ အဆုံးတွင် Reflection တစ်ခု တင်သွင်းပါ။</li>
                                    <li>• မေးခွန်း ၃ ခုစလုံးကို ဖြေဆိုပါ - <strong>ဘာလုပ်ခဲ့သလဲ (What)</strong>၊ <strong>ဘယ်လိုလုပ်ခဲ့သလဲ (How)</strong>၊ <strong>ဘာကြောင့်လုပ်ခဲ့သလဲ (Why)</strong>။</li>
                                    <li>• အသေးစိတ်ဖြစ်အောင် ဖော်ပြပြီး အသုံးပြုသည့်နည်းပညာများ၊ နည်းလမ်းများနှင့် အယူအဆများကို ဖော်ပြပါ။</li>
                                    <li>• <strong>ကျောင်းသားကိုယ်တိုင် Digital Signature ထိုးပြီးမှသာ</strong> အပတ်စဉ် Reflection တင်သွင်းမှု ပြီးမြောက်မည်ဖြစ်ပါသည်။</li>
                                    <li>• တင်သွင်းပြီးပါက Company Instructor စစ်ဆေးနိုင်ရန် <strong>Magic Link</strong> ကို Copy ကူး၍ ပေးပို့နိုင်ပါသည်။</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- 3. Review & Evaluation Process -->
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 hover:shadow-md transition">
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center text-lg font-black shrink-0">3</div>
                            <div>
                                <h3 class="text-sm font-bold text-slate-800 mb-1">စစ်ဆေးခြင်းနှင့် အကဲဖြတ်ခြင်း လုပ်ငန်းစဉ်</h3>
                                <ul class="text-xs text-slate-500 space-y-1.5 leading-relaxed">
                                    <li>• <strong>Company Instructor</strong> သည် Magic Link မှတစ်ဆင့် Weekly Reflection ကို စစ်ဆေးပြီး အမှတ်နှင့် မှတ်ချက်ပေးကာ လက်မှတ်ထိုးပေးမည်ဖြစ်ပါသည်။</li>
                                    <li>• <strong>CU Supervisor</strong> (တက္ကသိုလ်မှ ကြီးကြပ်ဆရာ/မ) က အပတ်စဉ် အကဲဖြတ်အမှတ် (<strong>Weekly Grade: A, B, C, D, F</strong>) နှင့် အကြံပြုချက် Feedback များ ပေးအပ်မည်ဖြစ်ပါသည်။</li>
                                    <li>• စစ်ဆေးချက် အခြေအနေများနှင့် Feedback များကို <strong>Dashboard</strong> နှင့် <strong>Notifications</strong> တွင် စစ်ဆေးနိုင်ပါသည်။</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- 4. Profile -->
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 hover:shadow-md transition">
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center text-lg font-black shrink-0">4</div>
                            <div>
                                <h3 class="text-sm font-bold text-slate-800 mb-1">Profile နှင့် Signature</h3>
                                <ul class="text-xs text-slate-500 space-y-1.5 leading-relaxed">
                                    <li>• သင့် Profile အချက်အလက်များကို အမြဲတမ်း update ထားပါ။</li>
                                    <li>• ပရော်ဖက်ရှင်နယ် Profile ပုံတစ်ခု တင်ပါ။</li>
                                    <li>• Report export များအတွက် သင့်digital signature ကို သတ်မှတ်ပါ။</li>
                                    <li>• သင့်signatureသည် export လုပ်ထားသည့် HTML report များတွင် ပေါ်လာပါမည်။</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- 5. Report Export -->
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 hover:shadow-md transition">
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-xl bg-purple-100 text-purple-600 flex items-center justify-center text-lg font-black shrink-0">5</div>
                            <div>
                                <h3 class="text-sm font-bold text-slate-800 mb-1">Report အစီရင်ခံစာများ</h3>
                                <ul class="text-xs text-slate-500 space-y-1.5 leading-relaxed">
                                    <li>• Dashboard မှ သင့် report ကို <strong>HTML</strong> သို့မဟုတ် <strong>CSV</strong> အဖြစ် export လုပ်ပါ။</li>
                                    <li>• သင့်report၏ hard copy အတွက် Print option ကို အသုံးပြုပါ။</li>
                                    <li>• Sidebar progress bar မှ သင့်internship တိုးတက်မှုကို ခြေရာခံပါ။</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Footer Note -->
                <div class="mt-8 text-center">
                    <p class="text-caption text-slate-400">မည်သည့်ပြဿနာမဆို သင့် CU Supervisor သို့မဟုတ် Company Instructor ကို ဆက်သွယ်ပါ။</p>
                </div>

            </div>
        </main>

    </div>
</div>

</body>
</html>
