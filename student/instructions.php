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
    foreach ($weeks as $wn => $wr) {
        $esc_wk_s = $conn->real_escape_string($wr['start']);
        $esc_wk_e = $conn->real_escape_string($wr['end']);
        $wc_r = $conn->query("SELECT COUNT(*) FROM daily_logs WHERE internship_id = {$esc_iid} AND log_date BETWEEN '{$esc_wk_s}' AND '{$esc_wk_e}'");
        if ($wc_r && $wc_r->num_rows > 0 && (int) $wc_r->fetch_row()[0] > 0) {
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
        body { font-family: 'Inter', sans-serif; }
        @keyframes gradientShift { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
        .animated-bg { background: linear-gradient(-45deg, #e0e7ff, #ede9fe, #fce7f3, #dbeafe, #d1fae5); background-size: 400% 400%; animation: gradientShift 20s ease infinite; }
        .glass-sidebar { background: rgba(30, 27, 75, 0.85); backdrop-filter: blur(20px); }
        .nav-link { color: rgba(255,255,255,0.55); font-weight: 500; }
        .nav-link:hover { color: #fff; background: rgba(255,255,255,0.1); }
        .active-nav { background: #9333ea; color: #fff; font-weight: 600; box-shadow: 0 4px 12px rgba(147,51,234,0.3); }
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
        <nav class="flex-1 py-4 space-y-1 px-3">
            <a href="student-dashboard.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">📝</span> Dashboard
            </a>
            <a href="analytics.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">📊</span> Analytics
            </a>
            <a href="log-history.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">📜</span> Log History
            </a>
            <a href="public-holiday.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">📅</span> Intern Period Calendar
            </a>
            <a href="instructions.php" class="nav-link active-nav flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200" data-section="instructions">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">📋</span> Instructions
            </a>
            <a href="profile.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">👤</span> Profile
            </a>
        </nav>
        <div class="p-3 border-t border-white/10">
            <a href="../logout.php" class="flex items-center gap-3 px-3 py-2.5 text-subtitle leading-relaxed font-semibold text-red-400 hover:text-red-300 hover:bg-white/10 rounded-lg transition-colors duration-200">
                <span class="w-5 h-5 flex items-center justify-center shrink-0">🚪</span> Logout
            </a>
        </div>
    </aside>

    <!-- ─── MAIN ─── -->
    <div class="flex-1 flex flex-col min-h-0">

        <!-- Top Bar -->
        <?php $pageTitle = 'သင်ကြားရေး'; $show_back_link = true; include '../includes/student-topbar.php'; ?>

        <!-- Content -->
        <main class="flex-1 overflow-y-auto p-6 no-print">
            <div class="max-w-4xl mx-auto">

                <!-- Header -->
                <div class="mb-8">
                    <h1 class="text-2xl font-black text-slate-800 mb-1">📋 သင်ကြားရေး</h1>
                    <p class="text-sm text-slate-500">သင့်internship report ကို တိကျစွာပြီးမြောက်အောင် အောက်ပါ လမ်းညွှန်ချက်များကို လိုက်နာပါ။</p>
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
                                    <li>• သင့်တက်ရောက်မှုအခြေအနေကို <strong>တက်ရောက်</strong> သို့မဟုတ် <strong>မတက်ရောက်</strong> ဟု မှတ်သားပါ။</li>
                                    <li>• ဆရာ/ဆရာမ စစ်ဆေးခြင်းမပြုမီ Log များကို ပြင်ဆင်ခြင်း သို့မဟုတ် ဖျက်ခြင်း ပြုနိုင်ပါသည်။</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- 2. Weekly Reflection -->
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 hover:shadow-md transition">
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-xl bg-indigo-100 text-indigo-600 flex items-center justify-center text-lg font-black shrink-0">2</div>
                            <div>
                                <h3 class="text-sm font-bold text-slate-800 mb-1">အပတ်စဉ် Reflection</h3>
                                <ul class="text-xs text-slate-500 space-y-1.5 leading-relaxed">
                                    <li>• အပတ်တိုင်း၏ အဆုံးတွင် reflection တစ်ခု တင်သွင်းပါ။</li>
                                    <li>• မေးခွန်း ၃ ခုစလုံးကို ဖြေဆိုပါ - <strong>ဘာ</strong>လုပ်ခဲ့သလဲ၊ <strong>ဘယ်လို</strong>လုပ်ခဲ့သလဲ၊ <strong>ဘာကြောင့်</strong>လုပ်ခဲ့သလဲ။</li>
                                    <li>• အသေးစိတ်ဖြစ်အောင် ဖော်ပြပြီး အသုံးပြုသည့်နည်းပညာများ၊ နည်းလမ်းများနှင့် အယူအဆများကို ဖော်ပြပါ။</li>
                                    <li>• Reflection များကို သင့်ဆရာ/ဆရာမနှင့် instructor တို့က စစ်ဆေးပါသည်။</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- 3. Supervisor Review -->
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 hover:shadow-md transition">
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center text-lg font-black shrink-0">3</div>
                            <div>
                                <h3 class="text-sm font-bold text-slate-800 mb-1">ဆရာ/ဆရာမ စစ်ဆေးခြင်း ဖြစ်စဉ်</h3>
                                <ul class="text-xs text-slate-500 space-y-1.5 leading-relaxed">
                                    <li>• သင့်ဆရာ/ဆရာမက နေ့စဉ် Log နှင့် အပတ်စဉ် Reflection တိုင်းကို စစ်ဆေးပါမည်။</li>
                                    <li>• Log များကို <strong>အတည်ပြု</strong>ခြင်း သို့မဟုတ် <strong>ပယ်ဖျက်</strong>ခြင်း ပြုနိုင်ပါသည်။</li>
                                    <li>• ပယ်ဖျက်ခံရပါက အကြံပြုချက်အပေါ် အခြေခံ၍ Log ကို ပြင်ဆင်ပြီး ပြန်လည်တင်သွင်းပါ။</li>
                                    <li>• စစ်ဆေးချက်အခြေအနေ update များအတွက် notification များကို ပုံမှန် စစ်ဆေးပါ။</li>
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

                    <!-- 5. Analytics -->
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 hover:shadow-md transition">
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-xl bg-purple-100 text-purple-600 flex items-center justify-center text-lg font-black shrink-0">5</div>
                            <div>
                                <h3 class="text-sm font-bold text-slate-800 mb-1">Analytics နှင့် Report များ</h3>
                                <ul class="text-xs text-slate-500 space-y-1.5 leading-relaxed">
                                    <li>• <strong>Analytics</strong> tab တွင် သင့်အပတ်စဉ် နာရီများ၊ တက်ရောက်မှုနှင့် စွမ်းဆောင်ရည်ကို ကြည့်ပါ။</li>
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
                    <p class="text-caption text-slate-400">မည်သည့်ပြဿနာမဆို သင့်ဆရာ/ဆရာမ သို့မဟုတ် instructor ကို ဆက်သွယ်ပါ။</p>
                </div>

            </div>
        </main>

    </div>
</div>

</body>
</html>
