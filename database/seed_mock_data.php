<?php
/**
 * ============================================================
 * InternReport Management System — Automated Mock Data Generator
 * File: database/seed_mock_data.php
 * 
 * Rules Enforced:
 * 1. Names & Signatures: English Language (e.g. Maung Maung, U Mya, U Thura)
 * 2. Daily Logs, Weekly Reflections & Feedbacks: မြန်မာဘာသာ (Myanmar / Burmese Language)
 * 3. Unified Password: P@ssword1 for ALL accounts
 * 4. Sequential Roll Numbers: 5CS-1, 5CS-2, ..., 5CS-10
 * 5. Strict @gmail.com domain
 * ============================================================
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/security_helper.php';

$db = $mysqli ?? $conn;
$message = '';
$error = '';
$stats = [];

$default_plain_pw = 'P@ssword1';
$default_pw_hash  = password_hash($default_plain_pw, PASSWORD_DEFAULT);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $action = $_POST['action'];

        if ($action === 'seed_fresh') {
            // Disable Foreign Key checks for clean wipe
            $db->query("SET FOREIGN_KEY_CHECKS = 0");
            $tables_to_truncate = [
                'notifications',
                'supervisor_weekly_evaluations',
                'report_evaluations',
                'magic_links',
                'weekly_reflections',
                'daily_logs',
                'student_profiles',
                'supervisor_academic_assignments',
                'companies',
                'academic_years',
                'users'
            ];
            foreach ($tables_to_truncate as $tbl) {
                $db->query("TRUNCATE TABLE `$tbl`");
            }
            $db->query("SET FOREIGN_KEY_CHECKS = 1");
        }

        // 1. System Settings
        $sys_settings = [
            ['default_student_password', $default_plain_pw],
            ['default_supervisor_password', $default_plain_pw],
            ['current_academic_year', '2023-2024']
        ];
        $st_setting = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($sys_settings as $s) {
            $st_setting->bind_param("ss", $s[0], $s[1]);
            $st_setting->execute();
        }

        // 2. Academic Years
        $db->query("INSERT INTO academic_years (year_label, start_date, end_date, is_current, status) VALUES 
            ('2023-2024', '2023-09-01', '2024-08-31', 1, 'Active'),
            ('2024-2025', '2024-09-01', '2025-08-31', 0, 'Upcoming')
            ON DUPLICATE KEY UPDATE is_current = VALUES(is_current), status = VALUES(status)");
        
        $res_ay = $db->query("SELECT id FROM academic_years WHERE year_label = '2023-2024' LIMIT 1");
        $ay_row = $res_ay->fetch_assoc();
        $ay_id = (int)($ay_row['id'] ?? 1);

        // 3. Admin Account
        $st_admin = $db->prepare("INSERT INTO users (username, email, password, role, is_first_login, status) VALUES ('admin', 'admin@gmail.com', ?, 'admin', 0, 'Active') ON DUPLICATE KEY UPDATE password=VALUES(password), role='admin'");
        $st_admin->bind_param("s", $default_pw_hash);
        $st_admin->execute();

        // 4. Companies
        $companies = [
            ['Soft Guide Technology', 'No. 45, Pyay Road, Kamayut Township, Yangon', 'U Aung Aung', 'softguidetech.hr@gmail.com', '09987654321', 'https://softguide.com.mm'],
            ['Nexlabs Myanmar', 'Tower B, 12th Floor, Myanmar Plaza, Bahan Township, Yangon', 'Daw Aye Myat', 'nexlabs.official@gmail.com', '09450012345', 'https://nexlabs.co'],
            ['Ace Data Systems', 'MICT Park, Building 9, Hlaing Township, Yangon', 'U Zaw Lwin', 'acedata.official@gmail.com', '01652301', 'https://acedatasystems.com'],
            ['Dirace Myanmar', 'No. 88, Kaba Aye Pagoda Road, Bahan Township, Yangon', 'U Myo Min', 'dirace.myanmar@gmail.com', '09666666666', 'https://dirace.com.mm'],
            ['KBZ Bank (Technology Dept)', 'No. 123, Strand Road, Botahtaung Township, Yangon', 'U Thant Zin', 'kbztech.internship@gmail.com', '012309811', 'https://www.kbzbank.com']
        ];
        $st_comp = $db->prepare("INSERT INTO companies (company_name, address, contact_person, contact_email, contact_phone, website) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE contact_person=VALUES(contact_person)");
        $company_ids = [];
        foreach ($companies as $c) {
            $st_comp->bind_param("ssssss", $c[0], $c[1], $c[2], $c[3], $c[4], $c[5]);
            $st_comp->execute();
            $cid = $db->insert_id ?: ($db->query("SELECT id FROM companies WHERE company_name = '{$c[0]}' LIMIT 1")->fetch_assoc()['id'] ?? null);
            if ($cid) $company_ids[] = (int)$cid;
        }

        // 5. Supervisors (University Faculty) — Names in English
        $supervisors = [
            ['U Mya (Lecturer)', 'umya.instructor@gmail.com', '09450099111', 'Faculty of Computer Science', 'Senior Lecturer'],
            ['Dr. Aung Kyaw', 'aungkyaw.supervisor@gmail.com', '09250011223', 'Faculty of Computer Science', 'Professor / Department Head'],
            ['Dr. Su Su Hlaing', 'susu.supervisor@gmail.com', '09450099887', 'Faculty of Computer Technology', 'Associate Professor']
        ];
        $supervisor_ids = [];
        $st_sup = $db->prepare("INSERT INTO users (username, email, phone, department, position, password, role, is_first_login, status) VALUES (?, ?, ?, ?, ?, ?, 'supervisor', 0, 'Active') ON DUPLICATE KEY UPDATE password=VALUES(password), status='Active'");
        foreach ($supervisors as $sup) {
            $st_sup->bind_param("ssssss", $sup[0], $sup[1], $sup[2], $sup[3], $sup[4], $default_pw_hash);
            $st_sup->execute();
            $uid = $db->insert_id ?: ($db->query("SELECT id FROM users WHERE email = '{$sup[1]}' LIMIT 1")->fetch_assoc()['id'] ?? null);
            if ($uid) {
                $supervisor_ids[] = (int)$uid;
                $db->query("INSERT IGNORE INTO supervisor_academic_assignments (supervisor_id, academic_year_id) VALUES ($uid, $ay_id)");
            }
        }

        // 6. Instructors (Company Mentors) — Names in English
        $instructors = [
            ['U Thura', 'thura.mentor@gmail.com', '09987654321', 'Software Development', 'Project Manager & Lead Dev'],
            ['Daw Yu Yu', 'yuyu.mentor@gmail.com', '09777777777', 'Engineering', 'Senior QA & Team Lead'],
            ['U Zaw Moe', 'zawmoe.mentor@gmail.com', '09666666666', 'Cloud & Systems', 'Senior Cloud Engineer'],
            ['U Zaw Min', 'zawmin.mentor@gmail.com', '09977881122', 'Software Engineering', 'Senior Fullstack Tech Lead']
        ];
        $instructor_ids = [];
        $st_inst = $db->prepare("INSERT INTO users (username, email, phone, department, position, password, role, is_first_login, status) VALUES (?, ?, ?, ?, ?, ?, 'instructor', 0, 'Active') ON DUPLICATE KEY UPDATE password=VALUES(password), status='Active'");
        foreach ($instructors as $ins) {
            $st_inst->bind_param("ssssss", $ins[0], $ins[1], $ins[2], $ins[3], $ins[4], $default_pw_hash);
            $st_inst->execute();
            $uid = $db->insert_id ?: ($db->query("SELECT id FROM users WHERE email = '{$ins[1]}' LIMIT 1")->fetch_assoc()['id'] ?? null);
            if ($uid) $instructor_ids[] = (int)$uid;
        }

        // 7. Realistic 12 Weeks Task Templates (မြန်မာဘာသာ အပြည့်အစုံ)
        $week_task_templates_my = [
            1 => [
                ['ကုမ္ပဏီမိတ်ဆက်နှင့် Development Environment Setup ပြုလုပ်ခြင်း', 'ကုမ္ပဏီ၏ လုပ်ငန်းခွင် စည်းမျဉ်းများ လေ့လာခြင်း၊ လိုအပ်သော IDE၊ PHP၊ MySQL၊ Git စသည်တို့ကို Setup ပြုလုပ်ခဲ့ပါသည်။', 'PHP, MySQL, Git, VS Code', 'Git version control စနစ်နှင့် Local development server အသုံးပြုပုံ', 'Local server ပတ်ဝန်းကျင် အချို့ configuration ညှိယူရခြင်း'],
                ['စနစ်ဖွဲ့စည်းပုံနှင့် Database Schema များ လေ့လာခြင်း', 'စီမံကိန်း၏ Database Schema များနှင့် ER Diagram များကို Mentor ၏ ရှင်းပြလမ်းညွှန်မှုဖြင့် အသေးစိတ် လေ့လာခဲ့ပါသည်။', 'MySQL Workbench, Draw.io, SQL', 'Relational database ဇယားများ ချိတ်ဆက်ထားပုံကို ဖတ်ရှုနားလည်ခြင်း', 'ဇယားများစွာ ချိတ်ဆက်ထားသော Foreign Key သဘောတရားများ'],
                ['UI Component Guidelines နှင့် Design System လေ့လာခြင်း', 'Figma Design System ကို အသုံးပြု၍ Typography၊ Color Palette များနှင့် Responsive Layout ပုံစံများကို လေ့လာခဲ့ပါသည်။', 'Figma, HTML5, CSS3, Tailwind', 'UI Design System နှင့် Responsive CSS Grid အသုံးပြုပုံ', 'Figma မှ အတိုင်းအတာများနှင့် CSS margin များကို ကိုက်ညီအောင် ညှိခြင်း'],
                ['Local Database Seeders နှင့် Table များ စမ်းသပ်တည်ဆောက်ခြင်း', 'Local WampServer တွင် စမ်းသပ်ရန် Database ဇယားများနှင့် နမူနာ Data များ ထည့်သွင်းစမ်းသပ်ခဲ့ပါသည်။', 'WampServer, PHPMyAdmin, SQL', 'MySQL Strict Mode နှင့် Data Type စစ်ဆေးမှုများ', 'Seed Data ထည့်သွင်းရာတွင် Unique Key ထပ်မနေစေရန် ညှိခြင်း'],
                ['အပတ်စဉ် Milestone စစ်ဆေးခြင်းနှင့် အပတ် (၂) Task ရေးဆွဲခြင်း', 'အပတ်စဉ် (၁) ပြီးမြောက်မှုများကို Mentor နှင့် သုံးသပ်ဆွေးနွေးခဲ့ပြီး အပတ် (၂) တွင် ဆက်လက်လုပ်ဆောင်မည့် အစီအစဉ်များ ရေးဆွဲခဲ့ပါသည်။', 'Jira, Slack, Google Meet', 'Agile နည်းစနစ်ဖြင့် Task များ ခွဲဝေဆောင်ရွက်ပုံ', 'Task ပြီးစီးနိုင်မည့် အချိန်ကို ခန့်မှန်းတွက်ချက်ခြင်း']
            ],
            2 => [
                ['User Authentication နှင့် Session Architecture စစ်ဆေးခြင်း', 'Session စီမံခန့်ခွဲမှု၊ Password Hash ပြုလုပ်ပုံ (BCrypt) နှင့် Role-Based Access Control (RBAC) စနစ်များကို လေ့လာစစ်ဆေးခဲ့ပါသည်။', 'PHP 8.2, BCrypt, Session API', 'လုံခြုံစိတ်ချရသော Session Fixation ကာကွယ်မှု နည်းလမ်းများ', 'Session သက်တမ်းကုန်ဆုံးမှု အခြေအနေများကို စီမံခြင်း'],
                ['Login စနစ်နှင့် Role Guard Logic များ ရေးသားခြင်း', 'User Role အလိုက် လမ်းကြောင်းလွှဲပေးမည့် Helper Function များနှင့် Permission Middleware များကို ရေးသားတည်ဆောက်ခဲ့ပါသည်။', 'PHP, OOP, HTTP Headers', 'Authentication နှင့် Authorization သဘောတရားများ ခွဲခြားရေးသားခြင်း', 'လော့ဂ်အင်မဝင်ထားသူများကို Login page သို့ စနစ်တကျ ပြန်လွှဲပေးခြင်း'],
                ['Login Form UI နှင့် Client-Side Validation ပြုလုပ်ခြင်း', 'ခေတ်မီဆန်းသစ်သော Login Form ဒီဇိုင်း၊ Password အဖွင့်/အပိတ် ခလုတ်နှင့် အမှားပြသမှု Toast မက်ဆေ့ခ်ျများ ရေးသားခဲ့ပါသည်။', 'HTML5, JavaScript, CSS', 'DOM Event Handling နှင့် Form Input စစ်ဆေးခြင်း', 'ဖုန်းမျက်နှာပြင်အရွယ်အစားများတွင် Form ပုံစံကျစေရန် ညှိခြင်း'],
                ['Change Password နှင့် Security Flow များ ရေးသားခြင်း', 'ပထမဆုံးအကြိမ် လော့ဂ်အင်ဝင်ရောက်သူများအတွက် Password ပြောင်းလဲခိုင်းသည့် စနစ်နှင့် Password အားကောင်းမှု စစ်ဆေးခြင်းများ ထည့်သွင်းခဲ့ပါသည်။', 'PHP, MySQL Prepared Statements', 'ခိုင်မာအားကောင်းသော Password စည်းမျဉ်းများ (Regex) ရေးသားခြင်း', 'Password အဟောင်းနှင့် အသစ် စစ်ဆေးမှုများ ပြုလုပ်ခြင်း'],
                ['Authentication စနစ် စမ်းသပ်စစ်ဆေးခြင်းနှင့် Code Review ပြုလုပ်ခြင်း', 'မှားယွင်းသော Password များ၊ SQL Injection စမ်းသပ်ချက်များကို Unit Test လုပ်ပြီး Mentor ထံ Code Review တင်ပြခဲ့ပါသည်။', 'Postman, PHPUnit, DevTools', 'Authentication စနစ်များအတွက် Test Case များ ရေးသားစစ်ဆေးခြင်း', 'Mentor ၏ အကြံပြုချက်အတိုင်း Input Sanitization များ ပြင်ဆင်ခြင်း']
            ],
            3 => [
                ['Student Dashboard နှင့် Profile Layout ပုံစံကြမ်း ရေးဆွဲခြင်း', 'ကျောင်းသား Dashboard အတွက် အကျဉ်းချုပ်စာရင်း၊ Progress Bar နှင့် Profile အချက်အလက်များ ပြသရန် Layout ကြမ်း ရေးဆွဲခဲ့ပါသည်။', 'Figma, CSS Grid, Flexbox', 'User Dashboard Visual Hierarchy ဒီဇိုင်း နည်းစနစ်များ', 'ကွန်ပျူတာနှင့် ဖုန်းနှစ်မျိုးလုံးတွင် အချက်အလက်များ ရှင်းလင်းစွာ မြင်ရစေခြင်း'],
                ['Student Profile စီမံခန့်ခွဲမှု အပိုင်း ရေးသားတည်ဆောက်ခြင်း', 'ကျောင်းသား၏ ကိုယ်ရေးအချက်အလက် ပြင်ဆင်ခြင်းနှင့် Profile ဓာတ်ပုံ တင်သွင်းသည့် File Upload စနစ်ကို ရေးသားခဲ့ပါသည်။', 'PHP, MySQLi, File Upload API', 'လုံခြုံသော File Upload Validation (MIME type, File size စစ်ဆေးခြင်း)', 'ဓာတ်ပုံမဟုတ်သော ဖိုင်များ အလွဲသုံးစား မတင်နိုင်အောင် စစ်ဆေးခြင်း'],
                ['Supervisor နှင့် Academic Year ချိတ်ဆက်မှု Query များ ရေးသားခြင်း', 'ကျောင်းသားတစ်ဦးစီအတွက် သက်ဆိုင်ရာ ဆရာနှင့် ပညာသင်နှစ် ချိတ်ဆက်ပေးမည့် Relational Query များကို ရေးသားခဲ့ပါသည်။', 'MySQL JOINs, Indexing', 'Multi-table JOIN စနစ်များ အသုံးပြု၍ Data ဆွဲထုတ်ခြင်း', 'ဆရာ မခွဲဝေရသေးသော ကျောင်းသားများအတွက် NULL တန်ဖိုးများကို စီမံခြင်း'],
                ['Internship Progress Bar နှင့် အပတ်တွက်ချက်မှု Engine ရေးသားခြင်း', 'Internship စတင်သည့်ရက်နှင့် ပြီးဆုံးသည့်ရက်ပေါ် မူတည်၍ လက်ရှိ အပတ်စဉ်ကို အလိုအလျောက် တွက်ချက်ပေးသည့် Algorithm ရေးသားခဲ့ပါသည်။', 'PHP DateTime API, DateInterval', 'Date Arithmetic နှင့် ရက်သတ္တပတ် တွက်ချက်မှု နည်းလမ်းများ', 'ရုံးပိတ်ရက်နှင့် ရုံးဖွင့်ရက် နယ်နိမိတ်များကို တိကျစွာ တွက်ချက်ခြင်း'],
                ['Profile အပိုင်း စမ်းသပ်ခြင်းနှင့် လုံခြုံရေး အမှားများ ပြင်ဆင်ခြင်း', 'XSS တိုက်ခိုက်မှုများကို ကာကွယ်ရန် htmlspecialchars အသုံးပြုခြင်းနှင့် Profile ပြင်ဆင်မှုများကို အပြည့်အစုံ စမ်းသပ်ခဲ့ပါသည်။', 'OWASP Standards, DevTools', 'Input Sanitization ဖြင့် Stored XSS ကာကွယ်ခြင်း', 'ဓာတ်ပုံ အပ်လုဒ် မအောင်မြင်ပါက ယာယီဖိုင်များ ရှင်းထုတ်ခြင်း']
            ],
            4 => [
                ['Daily Log ဖြည့်သွင်းသည့် Form ဒီဇိုင်း ဖန်တီးခြင်း', 'နေ့စဉ် လုပ်ဆောင်ချက်များ၊ စတင်/ပြီးဆုံးချိန်များနှင့် ကြာချိန်ကို အလိုအလျောက် တွက်ချက်ပေးသည့် Form ကို ရေးဆွဲခဲ့ပါသည်။', 'JavaScript, Flatpickr, CSS', 'Interactive Datepicker နှင့် ကြာချိန် အလိုအလျောက် တွက်ချက်ခြင်း', 'စတင်ချိန်နှင့် ပြီးဆုံးချိန်များ လွဲမှားမှု မဖြစ်အောင် စစ်ဆေးခြင်း'],
                ['Backend Log Validation နှင့် ရက်ကျော်မထည့်နိုင်အောင် ကန့်သတ်ခြင်း', 'အရင်ရက်များ မဖြည့်ဘဲ နောက်ရက်များကို ကြားဖြတ်မထည့်နိုင်စေမည့် Sequential Validation စည်းမျဉ်းများကို ရေးသားခဲ့ပါသည်။', 'PHP Prepared Statements, MySQL', 'Backend အဆင့်တွင် စီးပွားရေးဆိုင်ရာ စည်းမျဉ်းများ တင်းကြပ်စွာ ချမှတ်ခြင်း', 'ကျန်နေသော ရုံးဖွင့်ရက်များကို တိကျစွာ ရှာဖွေဖော်ထုတ်ခြင်း'],
                ['Attendance နှင့် ခွင့်တိုင်ကြားမှု အခြေအနေများ ထည့်သွင်းခြင်း', 'Present၊ Leave နှင့် Absent ရွေးချယ်မှုများ ထည့်သွင်းခဲ့ပြီး ခွင့်ရက်ဖြစ်ပါက အကြောင်းပြချက် ဖြည့်သွင်းရမည့် အပိုင်းကို ရေးသားခဲ့ပါသည်။', 'PHP, JS Event Listeners', 'ရွေးချယ်မှုပေါ် မူတည်၍ UI Input များကို ပြောင်းလဲပြသခြင်း', 'ပျက်ကွက်ရက်ဖြစ်ပါက Task ရိုက်ထည့်သည့် အကွက်များကို ပိတ်ထားခြင်း'],
                ['Daily Log စာရင်းဇယားနှင့် ပြင်ဆင်/ဖျက်ပစ်နိုင်သည့် စနစ် တည်ဆောက်ခြင်း', 'ဖြည့်သွင်းထားသော နေ့စဉ်မှတ်တမ်းများကို ဇယားဖြင့် ပြသခြင်း၊ ပြင်ဆင်ခြင်းနှင့် ဖျက်ပစ်ခြင်း Modal များကို ဖန်တီးခဲ့ပါသည်။', 'HTML Table, CSS Badges, Modals', 'အသုံးပြုရ လွယ်ကူသော Modal Dialog များနှင့် Data Table များ ဖန်တီးခြင်း', 'Log တစ်ခုကို ဖျက်လိုက်သည့်အခါ Confirmation Alert တောင်းဆိုခြင်း'],
                ['အပတ်စဉ် (၄) တိုးတက်မှု သုံးသပ်ခြင်းနှင့် မှတ်တမ်းများ တင်ပြခြင်း', '၄ ပတ်စာ နေ့စဉ်မှတ်တမ်းများကို ပြန်လည်စစ်ဆေးပြီး ပထမလပတ် အကဲဖြတ်မှုအတွက် Supervisor ဆရာထံ တင်ပြခဲ့ပါသည်။', 'Google Meet, Markdown, PDF', 'မိမိကိုယ်ကို ပြန်လည်သုံးသပ်ခြင်းနှင့် သင်ယူမှု မှတ်တမ်းတင်ခြင်း', 'နည်းပညာဆိုင်ရာ အသုံးအနှုန်းများကို စနစ်တကျ ရေးသားမှတ်တမ်းတင်ခြင်း']
            ],
            5 => [
                ['Weekly Reflection မေးခွန်း ၃ ခုပါဝင်သော စနစ် ရေးသားခြင်း', 'တစ်ပတ်တာအတွင်း လုပ်ဆောင်ခဲ့သည်များ (What, How, Why) ကို စနစ်တကျ ပြန်လည်သုံးသပ် ဖြည့်သွင်းသည့် စနစ်ကို တည်ဆောက်ခဲ့ပါသည်။', 'PHP, MySQL, Text Formatting', 'အင်ဂျင်နီယာဆိုင်ရာ Structured Reflection ရေးသားနည်း', 'စာပိုဒ်ခွဲများနှင့် ကွက်လပ်များကို စနစ်တကျ Format ထိန်းသိမ်းခြင်း'],
                ['Digital Signature (စာရိုက်ထည့်ခြင်းနှင့် ပုံဆွဲလက်မှတ်) စနစ် ထည့်သွင်းခြင်း', 'HTML5 Canvas အသုံးပြု၍ လက်မှတ်ရေးထိုးခြင်းနှင့် Font ဖြင့် စာလုံးစောင်း လက်မှတ်ထိုးခြင်း နည်းလမ်း ၂ မျိုးကို ထည့်သွင်းခဲ့ပါသည်။', 'HTML5 Canvas API, Base64 Image', 'Canvas ပေါ်မှ လက်မှတ်များကို Base64 ပုံရိပ်အဖြစ် ပြောင်းလဲသိမ်းဆည်းခြင်း', 'ဖုန်းမျက်နှာပြင်များတွင် Touch ဖြင့် ချောမွေ့စွာ လက်မှတ်ရေးထိုးနိုင်စေခြင်း'],
                ['Weekly Report Submit ပြုလုပ်ခြင်းနှင့် Lock ချသည့် စနစ် ရေးသားခြင်း', 'ကျောင်းသားမှ လက်မှတ်ထိုးပြီး Report တင်လိုက်ပါက ဆရာဘက်မှ စစ်ဆေးချိန်အထိ ပြင်ဆင်ခွင့် Lock ချလိုက်သည့် စနစ်ကို ရေးသားခဲ့ပါသည်။', 'MySQL Transactions, Status Locking', 'Database တွင် အခြေအနေ အဆင့်ဆင့် (State Machine) စီမံခန့်ခွဲခြင်း', 'တစ်ပြိုင်နက်တည်း အကြိမ်ကြိမ် Submit မလုပ်မိစေရန် ကာကွယ်ခြင်း'],
                ['Instructor Magic Link စနစ် တည်ဆောက်ခြင်း', 'ကုမ္ပဏီ Instructor များမှ Login ဝင်စရာမလိုဘဲ အလွယ်တကူ စစ်ဆေးနိုင်မည့် သက်တမ်းကန့်သတ်ပါ Token Link စနစ်ကို ရေးသားခဲ့ပါသည်။', 'Cryptographic Random, SHA-256', 'Passwordless Token-Based လုံခြုံရေး စနစ် တည်ဆောက်ခြင်း', 'အသုံးပြုပြီးသော Token များနှင့် သက်တမ်းကုန် Token များကို စစ်ဆေးခြင်း'],
                ['Report တင်သွင်းမှု စနစ်တစ်ခုလုံး အစမှအဆုံး စမ်းသပ်ခြင်း', 'Daily Log မှ Weekly Reflection၊ Digital Signature နှင့် Instructor စစ်ဆေးမှုအထိ အဆင့်အားလုံးကို End-to-End စမ်းသပ်ခဲ့ပါသည်။', 'MailHog, Chrome DevTools', 'Role အဆင့်ဆင့်ကြား Data စီးဆင်းမှုများကို အပြည့်အစုံ စစ်ဆေးခြင်း', 'အမှားအယွင်း ဖြစ်ပေါ်ပါက ပြသပေးမည့် Error မက်ဆေ့ခ်ျများ ညှိယူခြင်း']
            ],
            6 => [
                ['Instructor Review Dashboard မျက်နှာပြင် တည်ဆောက်ခြင်း', 'ကုမ္ပဏီ Mentor များအတွက် ကျောင်းသား၏ နေ့စဉ် Log များနှင့် Reflection များကို တစ်ပြိုင်နက် စစ်ဆေးနိုင်သည့် Portal ကို ရေးဆွဲခဲ့ပါသည်။', 'Tailwind CSS, PHP, Semantic HTML', 'အကဲဖြတ်စစ်ဆေးရ လွယ်ကူသော User Interface ဒီဇိုင်း', 'နေ့စဉ် Log ၅ ရက်စာနှင့် Reflection အဖြေများကို ဘေးချင်းယှဉ် ပြသခြင်း'],
                ['Instructor မှ အမှတ်ပေးခြင်း (Grading) နှင့် အတည်ပြုလက်မှတ်ထိုးခြင်း', 'Excellent၊ Good၊ Average စသည့် Grade များ၊ မှတ်ချက်များနှင့် Mentor ၏ လက်မှတ်ထိုး အတည်ပြုမှု အပိုင်းကို ရေးသားခဲ့ပါသည်။', 'PHP, MySQLi, AJAX', 'AJAX အသုံးပြု၍ Page Reload မဖြစ်ဘဲ အမှတ်ပေးအတည်ပြုခြင်း', 'Report ကို ပယ်ချ (Reject) ပါက ပြင်ဆင်ရန် အကြောင်းပြချက်များ ထည့်သွင်းခြင်း'],
                ['Notification အသိပေးချက် စနစ် ထည့်သွင်းတည်ဆောက်ခြင်း', 'Instructor မှ အတည်ပြုလိုက်ပါက (သို့မဟုတ်) ပယ်ချလိုက်ပါက ကျောင်းသားထံ ချက်ချင်း Notification ရောက်ရှိမည့် စနစ်ကို ရေးသားခဲ့ပါသည်။', 'MySQL, JSON API, Polling Helper', 'Event-Driven Notification စနစ် တည်ဆောက်ခြင်း', 'လွန်ခဲ့သော အချိန်များကို လူနားလည်လွယ်သော စာသား ("၂ နာရီအလိုက") အဖြစ် ပြောင်းလဲခြင်း'],
                ['Report Reject ပြန်လုပ်သည့် အခါ Lock ပြန်ဖြုတ်ပေးသည့် စနစ် စမ်းသပ်ခြင်း', 'Mentor မှ ပယ်ချပါက ကျောင်းသားထံ အကြောင်းကြားစာရောက်ပြီး Report ကို ပြန်လည်ပြင်ဆင်ခွင့် ပွင့်သွားသည့် စနစ်ကို စမ်းသပ်အတည်ပြုခဲ့ပါသည်။', 'PHP Session, MySQL Status Update', 'Report ၏ Lifecycle တစ်လျှောက် အခြေအနေ ပြောင်းလဲမှုများကို ထိန်းကျောင်းခြင်း', 'Reject ဖြစ်သွားသော Report များတွင် လက်မှတ်အသစ် ပြန်လည်ထိုးခိုင်းခြင်း'],
                ['Mid-Term အလယ်အလတ်ကာလ တိုးတက်မှု သုံးသပ်အကဲဖြတ်ခြင်း', 'ပထမ ၆ ပတ်စာ လုပ်ဆောင်ချက်များကို တက္ကသိုလ် ဆရာနှင့် ကုမ္ပဏီ Mentor တို့နှင့်အတူ တရားဝင် သုံးသပ်ဆွေးနွေးခဲ့ပါသည်။', 'Presentation Slides, PDF Reports', 'နည်းပညာဆိုင်ရာ တင်ပြဆွေးနွေးမှုနှင့် ရလဒ်များ ရှင်းလင်းတင်ပြခြင်း', 'ကျန်ရှိသော ဒုတိယ ၆ ပတ်အတွက် ရည်မှန်းချက်များကို ပြန်လည်ချိန်ညှိခြင်း']
            ],
            7 => [
                ['Supervisor University Dashboard မျက်နှာပြင် ရေးသားတည်ဆောက်ခြင်း', 'တက္ကသိုလ် ကြီးကြပ်သူ ဆရာများအတွက် ကျောင်းသားများ၏ တိုးတက်မှု၊ ပညာသင်နှစ်အလိုက် စာရင်းများကို ကြီးကြပ်နိုင်သည့် Portal ကို ရေးဆွဲခဲ့ပါသည်။', 'PHP, Chart.js, CSS Grid', 'ပညာရေးဆိုင်ရာ ကြီးကြပ်မှုအတွက် Data Visualization ပြုလုပ်ခြင်း', 'SQL GROUP BY အသုံးပြု၍ ကျောင်းသားများ၏ တင်သွင်းမှု အခြေအနေများကို စုစည်းတွက်ချက်ခြင်း'],
                ['Supervisor အပတ်စဉ် အမှတ်ပေး စနစ် (A/B/C/D/F) ရေးသားခြင်း', 'တက္ကသိုလ် အမှတ်ပေး စည်းမျဉ်းအရ Grade (A, B, C) နှင့် ပညာရေးဆိုင်ရာ အကြံပြုမှတ်ချက်များ ထည့်သွင်းပေးနိုင်သည့် စနစ်ကို ရေးသားခဲ့ပါသည်။', 'PHP, MySQL Prepared Statements', 'တက္ကသိုလ်၏ အကဲဖြတ် အမှတ်ပေး စည်းမျဉ်းများနှင့် ကိုက်ညီအောင် ရေးသားခြင်း', 'Instructor အတည်မပြုရသေးမီ Supervisor မှ ကြိုတင်အမှတ်မပေးနိုင်အောင် တားဆီးခြင်း'],
                ['နောက်ကျနေသော ကျောင်းသားများကို ရှာဖွေဖော်ထုတ်သည့် စနစ် တည်ဆောက်ခြင်း', 'Log မဖြည့်ဘဲ နောက်ကျနေသော ကျောင်းသားများကို အနီရောင် Badge ဖြင့် အလိုအလျောက် သတိပေး ဖော်ပြသည့် စနစ်ကို ရေးသားခဲ့ပါသည်။', 'MySQL Aggregations (DATEDIFF, COUNT)', 'ကျောင်းသားအခြေအနေ အချိန်နှင့်တစ်ပြေးညီ သိရှိနိုင်မည့် SQL Query များ ရေးသားခြင်း', 'ကျောင်းသားဦးရေ များပြားလာပါက Query ကြာချိန် မနှေးကွေးအောင် Optimize ပြုလုပ်ခြင်း'],
                ['Weekly Report များကို PDF/Print ထုတ်ယူနိုင်သည့် Layout ရေးဆွဲခြင်း', 'A4 စာရွက်ဖြင့် ပုံနှိပ်ထုတ်ယူရာတွင် သပ်ရပ်လှပစေမည့် တက္ကသိုလ်တံဆိပ်၊ ဇယားများနှင့် လက်မှတ်များပါသော Print CSS ကို ရေးသားခဲ့ပါသည်။', 'CSS @media print, Print Stylesheet', 'Print-friendly CSS Formatting နှင့် စာမျက်နှာ အကန့်အသတ်များ ညှိခြင်း', 'လက်မှတ်ထိုးကွက်များ စာမျက်နှာအကူးတွင် ပြတ်မသွားစေရန် ထိန်းသိမ်းခြင်း'],
                ['စနစ်အတွင်းရှိ Database Query များကို Refactor ပြုလုပ်ခြင်း', 'ထပ်ခါတလဲလဲ ဖြစ်နေသော Query များကို includes/ နှင့် config/ အောက်ရှိ Helper Function များအဖြစ် စုစည်းပြီး Code များကို သန့်စင်ခဲ့ပါသည်။', 'PHP 8.2, DRY Principle', 'Code Modularization နှင့် Clean Architecture နည်းစနစ်များ လေ့လာခြင်း', 'Refactoring ပြုလုပ်ပြီးနောက် မူလ လုပ်ဆောင်ချက်များ မပျက်စီးစေရန် စစ်ဆေးခြင်း']
            ],
            8 => [
                ['Notification Hub နှင့် အချိန်နှင့်တစ်ပြေးညီ Badge Counter များ တည်ဆောက်ခြင်း', 'မဖတ်ရသေးသော အသိပေးချက် အရေအတွက်ကို Badge ဖြင့် ပြသခြင်းနှင့် "အားလုံးဖတ်ပြီးအဖြစ် သတ်မှတ်ရန်" ခလုတ်ကို ရေးသားခဲ့ပါသည်။', 'AJAX, Fetch API, CSS Badges', 'Page Reload မလိုဘဲ အသိပေးချက် Badge များကို အလိုအလျောက် Update ပြုလုပ်ခြင်း', 'Database Index အသုံးပြု၍ Unread Notification Query များကို မြန်ဆန်စေခြင်း'],
                ['Admin မှ စနစ်ဆိုင်ရာ ကြေညာချက် (Broadcast Notice) ထုတ်ပြန်နိုင်သည့် စနစ်', 'အလုပ်သင် သတ်မှတ်ရက်များ၊ အစီရင်ခံစာ တင်ရမည့် ညွှန်ကြားချက်များကို ကျောင်းသားအားလုံးထံ ကြေညာနိုင်သည့် စနစ် ထည့်သွင်းခဲ့ပါသည်။', 'PHP, MySQL, HTML Sanitizer', 'Role အလိုက် ပစ်မှတ်ထား ကြေညာချက် ထုတ်ပြန်နိုင်သည့် စနစ်', 'ကြေညာချက် Banner များကို Dashboard တိုင်းတွင် သပ်ရပ်စွာ ပြသခြင်း'],
                ['Supervisor နှင့် ကျောင်းသားများကြား အကြံပြုဆွေးနွေးမှု မှတ်တမ်းစနစ်', 'ဆရာများမှ ကျောင်းသား၏ အပတ်စဉ် တိုးတက်မှုအပေါ် တိုက်ရိုက် အကြံပြုချက် ပေးပို့နိုင်သည့် စနစ်ကို တည်ဆောက်ခဲ့ပါသည်။', 'MySQL, PHP, CSS Card UI', 'ဆရာနှင့် ကျောင်းသားကြား အပြန်အလှန် ဆက်သွယ်မှု မှတ်တမ်းထားရှိခြင်း', 'Instructor ၏ ကုမ္ပဏီမှတ်ချက်နှင့် Supervisor ၏ တက္ကသိုလ်မှတ်ချက်များကို သီးခြားခွဲပြခြင်း'],
                ['Notification စနစ်၏ တိကျမှုနှင့် Index Performance စမ်းသပ်ခြင်း', 'Approval၊ Rejection၊ Warning စသည့် အခြေအနေများတွင် Notification စနစ်တကျ ရောက်ရှိခြင်း ရှိမရှိ အကြိမ်ကြိမ် စမ်းသပ်ခဲ့ပါသည်။', 'Manual QA, Postman API Testing', 'Database Index `idx_notif_user_read` ၏ အလုပ်လုပ်ပုံကို စစ်ဆေးခြင်း', 'Badge အရေအတွက် အနုတ်လက္ခဏာပြနိုင်သည့် Edge Case များကို ဖြေရှင်းခြင်း'],
                ['အပတ်စဉ် (၈) Sprint Review ပြုလုပ်ခြင်းနှင့် Code Walkthrough တင်ပြခြင်း', 'တည်ဆောက်ပြီးစီးခဲ့သော Notification နှင့် Supervisor Portal များကို Senior Engineer ထံ ရှင်းလင်းပြသခဲ့ပါသည်။', 'Live Demo, Code Review', 'System Architecture နှင့် Database Index များ ထည့်သွင်းရခြင်း အကြောင်းရင်းကို ရှင်းပြခြင်း', 'Notification Toast ပေါ်ထွက်လာသည့် Animation Timing များကို ညှိယူခြင်း']
            ],
            9 => [
                ['Company စီမံခန့်ခွဲမှု CRUD Module အပိုင်း ရေးသားတည်ဆောက်ခြင်း', 'Admin များအနေဖြင့် အလုပ်သင်လက်ခံသည့် မိတ်ဖက်ကုမ္ပဏီများ၊ တာဝန်ခံပုဂ္ဂိုလ်များနှင့် ဆက်သွယ်ရန်လိပ်စာများကို စီမံနိုင်သည့် စနစ် ရေးသားခဲ့ပါသည်။', 'PHP, MySQLi, Modal Forms', 'CSRF Token အကာအကွယ်ပါဝင်သော CRUD Pattern များ ရေးသားခြင်း', 'ကုမ္ပဏီနှင့် ကျောင်းသားများ ချိတ်ဆက်ထားသော Foreign Key ဆက်နွယ်မှုများကို ထိန်းသိမ်းခြင်း'],
                ['Academic Year စီမံခန့်ခွဲမှုနှင့် အဟောင်းများကို Archive ပြုလုပ်သည့် စနစ်', 'ပညာသင်နှစ်အသစ် ဖွင့်လှစ်ခြင်း၊ လက်ရှိနှစ်အဖြစ် သတ်မှတ်ခြင်းနှင့် ပြီးဆုံးသွားသော ပညာသင်နှစ်များကို Archive သိမ်းဆည်းသည့် စနစ် တည်ဆောက်ခဲ့ပါသည်။', 'MySQL Transactions, Foreign Keys', 'ပညာသင်နှစ်ဟောင်းမှ ကျောင်းသားများကို Read-Only အဖြစ် လုံခြုံစွာ သိမ်းဆည်းခြင်း', 'Active Academic Year တစ်ခုတည်းသာ ရှိစေရန် ထိန်းချုပ်ခြင်း'],
                ['User Account များကို စနစ်တကျ စီမံခန့်ခွဲခြင်းနှင့် Role ခွဲဝေမှုများ', 'ကျောင်းသားနှင့် ဆရာ အကောင့်များကို Default Password ဖြင့် ထည့်သွင်းပေးခြင်းနှင့် Role ခွဲဝေမှုများကို စနစ်တကျ ပြုလုပ်ခဲ့ပါသည်။', 'PHP, Security Helper, BCrypt', 'အသုံးပြုသူ အကောင့်များ စနစ်တကျ စီမံခန့်ခွဲမှု အကောင်းဆုံး နည်းစနစ်များ', 'ပညာသင်နှစ်တစ်ခုအတွင်း Roll Number များ ထပ်မနေစေရန် စစ်ဆေးခြင်း'],
                ['SQL Injection ကာကွယ်ရေးနှင့် လုံခြုံရေး စစ်ဆေးမှု (Security Audit) ပြုလုပ်ခြင်း', 'စနစ်အတွင်းရှိ Database Query များ အားလုံးကို Prepared Statements အပြည့်အဝ အသုံးပြုထားခြင်း ရှိမရှိ စစ်ဆေးပြင်ဆင်ခဲ့ပါသည်။', 'Static Code Analysis, Security Audit', 'SQL Injection နှင့် OWASP Top 10 လုံခြုံရေး အားနည်းချက်များကို ကာကွယ်ခြင်း', 'String Concatenation ဖြင့် ရေးထားသော Query အဟောင်းများကို Parameter Binding ဖြင့် အစားထိုးခြင်း'],
                ['အပတ်စဉ် (၉) လုံခြုံရေး အဆင့်မြှင့်တင်မှုများ သုံးသပ်ဆွေးနွေးခြင်း', 'လုံခြုံရေး ပြင်ဆင်မှုများကို ကုမ္ပဏီ၏ Lead Security Engineer နှင့် စစ်ဆေးပြီး အတည်ပြုချက် ရယူခဲ့ပါသည်။', 'Git Pull Request, Code Review', 'Pull Request များတွင် ပြင်ဆင်ချက်များကို ရှင်းလင်းစွာ ရေးသားတင်ပြခြင်း', 'Configuration ဖိုင်များတွင် ဖြစ်ပေါ်သော Merge Conflict များကို ဖြေရှင်းခြင်း']
            ],
            10 => [
                ['ကျောင်းသား၏ ၁၂ ပတ်စာ အလုပ်သင် မှတ်တမ်းအပြည့်အစုံ ကြည့်ရှုသည့် စနစ်', 'ကျောင်းသားတစ်ဦး၏ ၁၂ ပတ်စာ Daily Log များနှင့် Reflection များကို Accordion ဖြင့် အလွယ်တကူ ကြည့်ရှုနိုင်သည့် စနစ်ကို ရေးသားခဲ့ပါသည်။', 'JavaScript Accordion, CSS Transitions', 'Data အမြောက်အမြားကို Browser တွင် ပေါ့ပါးသွက်လက်စွာ ပြသနိုင်ခြင်း', 'လိုအပ်သည့် အပတ်များကိုသာ ဖွင့်ကြည့်နိုင်ရန် Lazy Rendering ပြုလုပ်ခြင်း'],
                ['စုစုပေါင်း အလုပ်လုပ်ချိန်နှင့် တက်ရောက်မှု ရာခိုင်နှုန်း တွက်ချက်ခြင်း', '၁၂ ပတ်လုံးအတွက် စုစုပေါင်း အလုပ်လုပ်ခဲ့သော နာရီများ၊ ရုံးတက်ရက်၊ ခွင့်ရက်နှင့် ပျမ်းမျှအလုပ်ချိန်များကို တွက်ချက်ပြသခဲ့ပါသည်။', 'PHP Date Utilities, SQL SUM/COUNT', 'ရုံးချိန် နာရီများကို တိကျစွာ ပေါင်းစပ်တွက်ချက်သည့် နည်းလမ်းများ', 'ကြာချိန်များကို "နာရီ:မိနစ်" (HH:MM) ပုံစံဖြင့် တိကျစွာ ဖော်ပြခြင်း'],
                ['အလုပ်သင် မှတ်တမ်းစာအုပ်တစ်ခုလုံးကို Print ထုတ်ယူနိုင်သည့် စနစ်', 'ကုမ္ပဏီအချက်အလက်၊ Mentor အချက်အလက်နှင့် ၁၂ ပတ်လုံး မှတ်တမ်းများ အပြည့်အစုံပါဝင်သော Portfolio Layout ကို ရေးဆွဲခဲ့ပါသည်။', 'HTML5, CSS Print Media, SVG Icons', 'တက္ကသိုလ်သို့ တင်သွင်းရန် အဆင့်မီ အစီရင်ခံစာ စာအုပ် ဒီဇိုင်း ဖန်တီးခြင်း', 'ဒစ်ဂျစ်တယ် လက်မှတ်များနှင့် တံဆိပ်တုံးများ Print ထုတ်ရာတွင် ကြည်လင်ပြတ်သားစေခြင်း'],
                ['Cross-Browser Compatibility နှင့် ဖုန်းမျက်နှာပြင်များတွင် စမ်းသပ်ခြင်း', 'Chrome၊ Firefox၊ Edge၊ Safari နှင့် မိုဘိုင်းဖုန်း အရွယ်အစား အားလုံးတွင် စနစ်၏ မျက်နှာပြင်များကို စမ်းသပ်စစ်ဆေး ပြင်ဆင်ခဲ့ပါသည်။', 'Chrome DevTools, Firefox Inspector', 'Browser အမျိုးမျိုးတွင် CSS Rendering ကွဲလွဲမှုများကို ညှိယူခြင်း', 'ဖုန်းမျက်နှာပြင် အသေးများတွင် ဇယားများ အပြင်ဘက် မလျှံထွက်အောင် ညှိခြင်း'],
                ['တက္ကသိုလ် ပါမောက္ခဆရာနှင့် အပတ်စဉ် (၁၀) တိုးတက်မှု သုံးသပ်ဆွေးနွေးခြင်း', '၁၂ ပတ်စာ မှတ်တမ်းစာအုပ် ပုံစံကို ဒေါက်တာအောင်ကျော်ထံ တင်ပြပြီး နောက်ဆုံး အစီရင်ခံစာအတွက် လိုအပ်ချက်များ ရယူခဲ့ပါသည်။', 'Academic Advisory Meeting', 'တက္ကသိုလ်၏ ပညာရေးဆိုင်ရာ အစီရင်ခံစာ သတ်မှတ်ချက်များနှင့် ညှိနှိုင်းခြင်း', 'စာအုပ် Layout တွင် တက္ကသိုလ်တံဆိပ်နှင့် ဌာနအချက်အလက်များ ထည့်သွင်းခြင်း']
            ],
            11 => [
                ['Database Index Tuning ဖြင့် စနစ်၏ စွမ်းဆောင်ရည်ကို မြှင့်တင်ခြင်း', 'မကြာခဏ Query လုပ်ရသော ဇယားကော်လံများ (`user_id`, `internship_id`, `week_number`, `log_date`) တွင် Index များ ထည့်သွင်းခဲ့ပါသည်။', 'MySQL EXPLAIN, INDEX Tuning', 'Database Query Execution Plan (EXPLAIN) ကို လေ့လာဆန်းစစ်ခြင်း', 'Data များပြားလာသော်လည်း စက္ကန့်ပိုင်းအတွင်း မြန်ဆန်စွာ အလုပ်လုပ်စေခြင်း'],
                ['အသုံးပြုသူများ နားလည်လွယ်သော မြန်မာဘာသာ Toast Alert များ ပြင်ဆင်ခြင်း', 'အမှားအယွင်းများ ဖြစ်ပေါ်ပါက နားလည်ရခက်သော Error များအစား ဖော်ရွေရှင်းလင်းသော မြန်မာဘာသာ အကြံပြုချက်များကို အစားထိုးခဲ့ပါသည်။', 'Custom Toast JS, CSS', 'User Experience (UX) အဆင့်အတန်း မြှင့်တင်ရေး နည်းလမ်းများ', 'Toast အသိပေးချက်များ တစ်ခုနှင့်တစ်ခု ထပ်မနေစေရန် Animation ညှိခြင်း'],
                ['အလုပ်သင် ဆင်းသက်မှု နောက်ဆုံး Presentation Slides များ ပြင်ဆင်ခြင်း', '၁၂ ပတ်အတွင်း ရေးသားတည်ဆောက်ခဲ့သော စနစ်များ၊ ရရှိခဲ့သော နည်းပညာ အတွေ့အကြုံများကို Slides အဖြစ် စနစ်တကျ ပြင်ဆင်ခဲ့ပါသည်။', 'PowerPoint, Architecture Diagrams', 'နည်းပညာဆိုင်ရာ တင်ပြမှုနှင့် ရလဒ်များကို အကျဉ်းချုပ် ရှင်းလင်းတင်ပြနိုင်စွမ်း', 'အဓိက အောင်မြင်မှုများနှင့် စနစ်၏ အားသာချက်များကို မီးမောင်းထိုးပြသခြင်း'],
                ['ကုမ္ပဏီ Mentor နှင့်အတူ Presentation အစမ်းလေ့ကျင့်ခြင်း (Dry Run)', 'ကုမ္ပဏီ အင်ဂျင်နီယာ အဖွဲ့နှင့်အတူ စမ်းသပ်တင်ပြခဲ့ပြီး မေးခွန်းများ ဖြေကြားပုံနှင့် အချိန်စီမံခန့်ခွဲမှုများကို လေ့ကျင့်ပြင်ဆင်ခဲ့ပါသည်။', 'Zoom Rehearsal, Q&A Practice', 'နည်းပညာဆိုင်ရာ မေးခွန်းများကို တိကျသေချာစွာ ဖြေကြားနိုင်ရန် လေ့ကျင့်ခြင်း', 'Slide ကူးပြောင်းမှုများနှင့် တင်ပြပုံ အသံနေအထားများကို ပြင်ဆင်ခြင်း'],
                ['Code များကို PSR-12 စံနှုန်းအတိုင်း သန့်စင်ခြင်းနှင့် Git Repo သို့ Push လုပ်ခြင်း', 'မလိုအပ်သော Debug Code များနှင့် စာကြောင်းများကို ရှင်းထုတ်ပြီး စက်မှုလုပ်ငန်းသုံး PSR-12 စံနှုန်းအတိုင်း Clean Code အဖြစ် ပြုပြင်ခဲ့ပါသည်။', 'Git, PSR-12 Standards, VS Code', 'စက်မှုလုပ်ငန်းသုံး Coding Standards များနှင့် Repository သန့်ရှင်းမှု ထိန်းသိမ်းခြင်း', 'ကျန်ရှိနေသော console.log များနှင့် စမ်းသပ်စာသားများကို ရှင်းလင်းခြင်း']
            ],
            12 => [
                ['အလုပ်သင် အစီရင်ခံစာ စာအုပ် အပြီးသတ် စုစည်းချုပ်လုပ်ခြင်း', '၁၂ ပတ်စာ အပတ်စဉ် သုံးသပ်ချက်များ၊ ဆရာများနှင့် Mentor ၏ လက်မှတ်များ အားလုံး ပြည့်စုံအောင် စစ်ဆေးပြီး အပြီးသတ် ချုပ်လုပ်ခဲ့ပါသည်။', 'Acrobat PDF, Markdown, Print', 'နည်းပညာဆိုင်ရာ စာတမ်း အပြီးသတ် ပြုစုထုတ်ဝေခြင်း', 'လက်မှတ်ရက်စွဲများနှင့် အမှတ်ပေးဇယားများ အားလုံး မှန်ကန်မှု ရှိမရှိ စစ်ဆေးခြင်း'],
                ['တက္ကသိုလ် စာမေးပွဲ အကဲဖြတ်အဖွဲ့ထံ နောက်ဆုံး စာတမ်းဖတ်ကြား တင်ပြခြင်း', 'တက္ကသိုလ် အကဲဖြတ် ဘုတ်အဖွဲ့ထံ မိနစ် ၃၀ ကြာ အလုပ်သင် စာတမ်းဖတ်ကြား တင်ပြခဲ့ပြီး ဂုဏ်ထူးဆောင် အမှတ်ဖြင့် အောင်မြင်ခဲ့ပါသည်။', 'Live Defense, Slides, Demo', 'ပညာရေးဆိုင်ရာ တရားဝင် စာတမ်းဖတ်ကြား တင်ပြနိုင်စွမ်း', 'Database Concurrency နှင့် လုံခြုံရေးဆိုင်ရာ မေးခွန်းများကို ကျွမ်းကျင်စွာ ဖြေကြားခြင်း'],
                ['လုပ်ငန်းခွင် စီမံကိန်း လွှဲပြောင်းပေးအပ်ခြင်းနှင့် Documentation ရေးသားခြင်း', 'နောက်ရောက်လာမည့် ဂျူနီယာ အလုပ်သင်များအတွက် System Architecture နှင့် API Endpoint လမ်းညွှန်ချက်များကို မှတ်တမ်းတင် ပေးအပ်ခဲ့ပါသည်။', 'Markdown, README.md, Postman Docs', 'ဆော့ဖ်ဝဲလ် အင်ဂျင်နီယာ လုပ်ငန်းခွင် လွှဲပြောင်းမှုဆိုင်ရာ စာတမ်းပြုစုခြင်း', 'ဆော့ဖ်ဝဲလ် အသစ်ပြင်ဆင်ရေးသားသူများ အလွယ်တကူ နားလည်နိုင်စေမည့် လမ်းညွှန်'],
                ['ကုမ္ပဏီ Mentor နှင့် အလုပ်သင် ပြီးဆုံးမှု သုံးသပ်ခြင်းနှင့် လက်မှတ်ရယူခြင်း', 'ကုမ္ပဏီ HR နှင့် အင်ဂျင်နီယာ ဌာနမှူးတို့နှင့်အတူ Exit Interview ပြုလုပ်ခဲ့ပြီး အလုပ်သင် အောင်မြင်ပြီးဆုံးကြောင်း ဂုဏ်ပြုလက်မှတ် ရယူခဲ့ပါသည်။', 'HR Exit Meeting, Certificate Handover', 'မိမိ၏ နည်းပညာ တိုးတက်မှုများကို ပြန်လည်သုံးသပ်ခြင်းနှင့် အနာဂတ် အလုပ်အကိုင် အခွင့်အလမ်း ဆွေးနွေးခြင်း', 'ကုမ္ပဏီမှ အမြဲတမ်း ဝန်ထမ်းအဖြစ် ကမ်းလှမ်းမှုဆိုင်ရာ အခွင့်အလမ်းများ ဆွေးနွေးခြင်း'],
                ['အလုပ်သင်ကာလ အောင်မြင်စွာ ပြီးဆုံးခြင်းနှင့် စနစ်တွင် အပြီးသတ် အတည်ပြုခြင်း', 'အလုပ်သင် အခြေအနေကို "Completed" အဖြစ် တရားဝင် အတည်ပြုခဲ့ပြီး ၁၂ ပတ်တာ အလုပ်သင် အတွေ့အကြုံကို အောင်မြင်စွာ ပြီးဆုံးစေခဲ့ပါသည်။', 'System Milestone, Celebration', 'ဆော့ဖ်ဝဲလ် အင်ဂျင်နီယာ ဘဝအတွက် အရေးပါသော အလုပ်သင်ကာလ ပြီးမြောက်ခြင်း', '၁၂ ပတ်တာ လက်တွေ့လုပ်ငန်းခွင် နည်းပညာနှင့် လူမှုဆက်ဆံရေး တိုးတက်မှုများကို ဂုဏ်ယူစွာ မှတ်တမ်းတင်ခြင်း']
            ]
        ];

        // 8. Students List in SEQUENTIAL Roll Numbers: 5CS-1 to 5CS-10 (Names in English)
        $students_data = [
            [
                'roll' => '5CS-1',
                'username' => '5CS-1',
                'email' => 'mgmg.5cs1@gmail.com',
                'full_name' => 'Maung Maung',
                'major' => 'Computer Science',
                'phone' => '09123456789',
                'job_role' => 'Fullstack Web Intern',
                'comp_idx' => 0, // Soft Guide Technology
                'sup_idx' => 0,  // U Mya (Lecturer)
                'inst_idx' => 0, // U Thura
                'weeks_count' => 12
            ],
            [
                'roll' => '5CS-2',
                'username' => '5CS-2',
                'email' => 'susu.5cs2@gmail.com',
                'full_name' => 'Su Su',
                'major' => 'Computer Science',
                'phone' => '09111111111',
                'job_role' => 'Frontend UI/UX Intern',
                'comp_idx' => 2, // Ace Data Systems
                'sup_idx' => 0,  // U Mya (Lecturer)
                'inst_idx' => 1, // Daw Yu Yu
                'weeks_count' => 12
            ],
            [
                'roll' => '5CS-3',
                'username' => '5CS-3',
                'email' => 'kyawkyaw.5cs3@gmail.com',
                'full_name' => 'Kyaw Kyaw',
                'major' => 'Computer Science',
                'phone' => '09222222222',
                'job_role' => 'Cloud & DevOps Intern',
                'comp_idx' => 3, // Dirace Myanmar
                'sup_idx' => 0,  // U Mya (Lecturer)
                'inst_idx' => 2, // U Zaw Moe
                'weeks_count' => 10
            ],
            [
                'roll' => '5CS-4',
                'username' => '5CS-4',
                'email' => 'minmin.5cs4@gmail.com',
                'full_name' => 'Min Min Aung',
                'major' => 'Computer Science',
                'phone' => '09450011223',
                'job_role' => 'Fullstack Web Developer Intern',
                'comp_idx' => 1, // Nexlabs Myanmar
                'sup_idx' => 1,  // Dr. Aung Kyaw
                'inst_idx' => 3, // U Zaw Min
                'weeks_count' => 12
            ],
            [
                'roll' => '5CS-5',
                'username' => '5CS-5',
                'email' => 'ayeaye.5cs5@gmail.com',
                'full_name' => 'Aye Aye Mon',
                'major' => 'Computer Science',
                'phone' => '09790088776',
                'job_role' => 'Frontend UI/UX Intern',
                'comp_idx' => 2, // Ace Data Systems
                'sup_idx' => 1,  // Dr. Aung Kyaw
                'inst_idx' => 1, // Daw Yu Yu
                'weeks_count' => 8
            ],
            [
                'roll' => '5CS-6',
                'username' => '5CS-6',
                'email' => 'htethtet.5cs6@gmail.com',
                'full_name' => 'Htet Htet Lin',
                'major' => 'Computer Science',
                'phone' => '09960055443',
                'job_role' => 'QA & Automation Intern',
                'comp_idx' => 0, // Soft Guide Technology
                'sup_idx' => 1,  // Dr. Aung Kyaw
                'inst_idx' => 0, // U Thura
                'weeks_count' => 6
            ],
            [
                'roll' => '5CS-7',
                'username' => '5CS-7',
                'email' => 'aungmyat.5cs7@gmail.com',
                'full_name' => 'Aung Myint Myat',
                'major' => 'Computer Science',
                'phone' => '09251122334',
                'job_role' => 'Security & Systems Intern',
                'comp_idx' => 4, // KBZ Bank
                'sup_idx' => 2,  // Dr. Su Su Hlaing
                'inst_idx' => 0, // U Thura
                'weeks_count' => 4
            ],
            [
                'roll' => '5CS-8',
                'username' => '5CS-8',
                'email' => 'phyuphyu.5cs8@gmail.com',
                'full_name' => 'Phyu Phyu Thin',
                'major' => 'Computer Science',
                'phone' => '09420011998',
                'job_role' => 'Backend API Intern',
                'comp_idx' => 1, // Nexlabs Myanmar
                'sup_idx' => 2,  // Dr. Su Su Hlaing
                'inst_idx' => 3, // U Zaw Min
                'weeks_count' => 2
            ],
            [
                'roll' => '5CS-9',
                'username' => '5CS-9',
                'email' => 'zinmin.5cs9@gmail.com',
                'full_name' => 'Zin Min Thu',
                'major' => 'Computer Science',
                'phone' => '09780099881',
                'job_role' => 'Mobile App Intern',
                'comp_idx' => 3, // Dirace Myanmar
                'sup_idx' => 2,  // Dr. Su Su Hlaing
                'inst_idx' => 2, // U Zaw Moe
                'weeks_count' => 1
            ],
            [
                'roll' => '5CS-10',
                'username' => '5CS-10',
                'email' => 'thurein.5cs10@gmail.com',
                'full_name' => 'Thurein Tun',
                'major' => 'Computer Science',
                'phone' => '09950011229',
                'job_role' => 'Junior Web Developer Intern',
                'comp_idx' => 0, // Soft Guide Technology
                'sup_idx' => 0,  // U Mya (Lecturer)
                'inst_idx' => 0, // U Thura
                'weeks_count' => 0
            ]
        ];

        $st_u = $db->prepare("INSERT INTO users (username, email, phone, password, role, is_first_login, academic_year, academic_year_id, status) VALUES (?, ?, ?, ?, 'student', 0, '2023-2024', ?, 'Active') ON DUPLICATE KEY UPDATE password=VALUES(password), status='Active'");
        $st_sp = $db->prepare("INSERT INTO student_profiles (user_id, supervisor_id, instructor_id, company_id, full_name, student_roll, major, phone, company_name, job_role, instructor_name, instructor_email, instructor_phone, internship_start_date, internship_end_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE full_name=VALUES(full_name)");

        $st_log = $db->prepare("INSERT INTO daily_logs (internship_id, log_date, attendance_status, task_title, task_detail, tasks_performed, actual_tasks, tools_used, learnt_skills, challenges, start_time, end_time, calculated_duration) VALUES (?, ?, 'present', ?, ?, ?, ?, ?, ?, ?, '09:00', '17:00', '08:00') ON DUPLICATE KEY UPDATE task_title=VALUES(task_title)");
        
        $st_ref = $db->prepare("INSERT INTO weekly_reflections (internship_id, week_number, what_done, how_done, why_done) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE what_done=VALUES(what_done)");
        
        $st_eval = $db->prepare("INSERT INTO report_evaluations (student_id, week_number, grade, comment, instructor_comments, signature_type, signature_value, student_signature_type, student_signature_value, report_status) VALUES (?, ?, ?, ?, ?, 'typed', ?, 'typed', ?, ?) ON DUPLICATE KEY UPDATE report_status=VALUES(report_status)");
        
        $st_sup_eval = $db->prepare("INSERT INTO supervisor_weekly_evaluations (student_id, week_number, supervisor_id, weekly_grade, supervisor_comments) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE weekly_grade=VALUES(weekly_grade)");

        $st_notif = $db->prepare("INSERT INTO notifications (user_id, title, message, type, related_week, student_id, is_read) VALUES (?, ?, ?, ?, ?, ?, 0)");

        $internship_start_str = '2023-10-02'; // Monday
        $internship_end_str   = '2023-12-22'; // Friday (12 weeks)

        $total_students_created = 0;
        $total_logs_created = 0;
        $total_reflections_created = 0;

        foreach ($students_data as $sdata) {
            $st_u->bind_param("ssssi", $sdata['username'], $sdata['email'], $sdata['phone'], $default_pw_hash, $ay_id);
            $st_u->execute();
            $student_uid = $db->insert_id ?: ($db->query("SELECT id FROM users WHERE email = '{$sdata['email']}' LIMIT 1")->fetch_assoc()['id'] ?? null);
            
            if (!$student_uid) continue;
            $total_students_created++;

            $comp_id = $company_ids[$sdata['comp_idx']] ?? $company_ids[0];
            $comp_name = $companies[$sdata['comp_idx']][0];
            $sup_id = $supervisor_ids[$sdata['sup_idx']] ?? $supervisor_ids[0];
            $inst_id = $instructor_ids[$sdata['inst_idx']] ?? $instructor_ids[0];
            $inst_name = $instructors[$sdata['inst_idx']][0];
            $inst_email = $instructors[$sdata['inst_idx']][1];
            $inst_phone = $instructors[$sdata['inst_idx']][2];

            // Create student profile
            $st_sp->bind_param(
                "iiiisssssssssss",
                $student_uid,
                $sup_id,
                $inst_id,
                $comp_id,
                $sdata['full_name'],
                $sdata['roll'],
                $sdata['major'],
                $sdata['phone'],
                $comp_name,
                $sdata['job_role'],
                $inst_name,
                $inst_email,
                $inst_phone,
                $internship_start_str,
                $internship_end_str
            );
            $st_sp->execute();

            $weeks_to_generate = $sdata['weeks_count'];
            $base_start = new DateTime($internship_start_str);

            for ($w = 1; $w <= $weeks_to_generate; $w++) {
                $templates = $week_task_templates_my[$w] ?? $week_task_templates_my[1];
                
                $week_monday = clone $base_start;
                $days_offset = ($w - 1) * 7;
                $week_monday->modify("+{$days_offset} days");

                for ($d = 0; $d < 5; $d++) {
                    $day_date = clone $week_monday;
                    $day_date->modify("+{$d} days");
                    $date_str = $day_date->format('Y-m-d');
                    
                    $t = $templates[$d] ?? $templates[0];
                    $t_title = $t[0];
                    $t_detail = $t[1];
                    $t_performed = $t[1];
                    $t_actual = $t[1];
                    $t_tools = $t[2];
                    $t_skills = $t[3];
                    $t_challenges = $t[4];

                    $st_log->bind_param("issssssss", $student_uid, $date_str, $t_title, $t_detail, $t_performed, $t_actual, $t_tools, $t_skills, $t_challenges);
                    $st_log->execute();
                    $total_logs_created++;
                }

                // Weekly Reflection (မြန်မာဘာသာ)
                $what = "ယခု အပတ်စဉ် ({$w}) တွင် သတ်မှတ်ထားသော Task များဖြစ်သည့် " . implode('၊ ', array_map(fn($item) => $item[0], $templates)) . " တို့ကို အောင်မြင်စွာ ဆောင်ရွက်ပြီးစီးခဲ့ပါသည်။";
                $how = "ကုမ္ပဏီ Mentor ဖြစ်သူ {$inst_name} ၏ လမ်းညွှန်မှုဖြင့် Clean Code စည်းမျဉ်းများကို လိုက်နာကာ {$templates[0][2]} နည်းပညာများကို အသုံးပြု၍ စနစ်တကျ ရေးသားတည်ဆောက်ခဲ့ပါသည်။";
                $why = "လက်တွေ့လုပ်ငန်းခွင်တွင် အသင်းအဖွဲ့နှင့် ပူးပေါင်းဆောင်ရွက်တတ်စေရန်နှင့် ဆော့ဖ်ဝဲလ် အင်ဂျင်နီယာဆိုင်ရာ ပြဿနာများကို ထိရောက်စွာ ဖြေရှင်းတတ်စေရန် ဖြစ်ပါသည်။";

                $st_ref->bind_param("iisss", $student_uid, $w, $what, $how, $why);
                $st_ref->execute();
                $total_reflections_created++;

                // Evaluations & Feedback (မြန်မာဘာသာ) + Signatures in English
                $eval_status = 'approved_by_supervisor';
                $grade = ($w % 3 === 0) ? 'excellent' : 'good';
                $sup_grade = ($w % 3 === 0) ? 'A' : 'B';
                
                $inst_comment = "အပတ်စဉ် ({$w}) တာဝန်များကို အချိန်မီ ပြီးစီးအောင် ကြိုးစားဆောင်ရွက်နိုင်ခဲ့သည်။ Code ရေးသားပုံ သပ်ရပ်ပြီး လေ့လာလိုစိတ် အလွန်ကောင်းမွန်ပါသည်။";
                $comment = "သတ်မှတ်ထားသော Task များကို အောင်မြင်စွာ ပြီးမြောက်ခဲ့သည်။";
                $sup_comment = "အပတ်စဉ် Daily Log များနှင့် Reflection များကို စနစ်တကျ မှန်ကန်စွာ ဖြည့်သွင်းထားသည်။ လက်တွေ့ကျွမ်းကျင်မှု တိုးတက်လာသည်ကို တွေ့ရပါသည်။";
                
                $student_sig = $sdata['full_name']; // e.g. "Maung Maung"
                $inst_sig = $inst_name;             // e.g. "U Thura"

                $st_eval->bind_param("iissssss", $student_uid, $w, $grade, $comment, $inst_comment, $inst_sig, $student_sig, $eval_status);
                $st_eval->execute();

                $st_sup_eval->bind_param("iiiss", $student_uid, $w, $sup_id, $sup_grade, $sup_comment);
                $st_sup_eval->execute();
            }

            if ($weeks_to_generate > 0) {
                $n_title = "အပတ်စဉ် အစီရင်ခံစာ အတည်ပြုပြီးပါပြီ";
                $n_msg = "သင်၏ အပတ်စဉ် ({$weeks_to_generate}) အစီရင်ခံစာကို ဆရာမှ စစ်ဆေးအတည်ပြုပြီး ဖြစ်ပါသည်။";
                $n_type = "supervisor_approved";
                $st_notif->bind_param("isssii", $student_uid, $n_title, $n_msg, $n_type, $weeks_to_generate, $student_uid);
                $st_notif->execute();
            }
        }

        $stats = [
            'companies' => count($companies),
            'supervisors' => count($supervisors),
            'instructors' => count($instructors),
            'students' => $total_students_created,
            'daily_logs' => $total_logs_created,
            'weekly_reflections' => $total_reflections_created,
            'weeks_generated' => 12
        ];

        $message = "Name & Signatures (English) နှင့် Daily Logs/Reflections (မြန်မာလို) ဖြင့် အောင်မြင်စွာ ထည့်သွင်းပြီးပါပြီ!";
    } catch (Exception $ex) {
        $error = "Data ထည့်သွင်းရာတွင် Error ဖြစ်ပေါ်ခဲ့ပါသည်: " . $ex->getMessage();
    }
}

// Fetch current counts in DB
$count_users = $db->query("SELECT COUNT(*) FROM users")->fetch_row()[0] ?? 0;
$count_students = $db->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetch_row()[0] ?? 0;
$count_logs = $db->query("SELECT COUNT(*) FROM daily_logs")->fetch_row()[0] ?? 0;
$count_refs = $db->query("SELECT COUNT(*) FROM weekly_reflections")->fetch_row()[0] ?? 0;
$count_companies = $db->query("SELECT COUNT(*) FROM companies")->fetch_row()[0] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>InternReport System — Auto Data Generator</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Padauk:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', 'Padauk', sans-serif; }
    </style>
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen py-10 px-4">
    <div class="max-w-5xl mx-auto">
        <!-- Header -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center p-3 bg-indigo-500/10 rounded-2xl border border-indigo-500/20 mb-4">
                <svg class="w-10 h-10 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                </svg>
            </div>
            <h1 class="text-3xl font-extrabold text-white tracking-tight">InternReport System Mock Data Generator</h1>
            <p class="text-slate-400 mt-2 text-sm sm:text-base">
                Name &amp; Signatures: <span class="text-indigo-400 font-bold">English</span> | Daily Logs, Reflections &amp; Feedbacks: <span class="text-emerald-400 font-bold">မြန်မာဘာသာ</span>
            </p>
        </div>

        <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 flex items-start gap-3">
                <svg class="w-6 h-6 text-emerald-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                <div>
                    <h3 class="font-bold text-emerald-200"><?= htmlspecialchars($message) ?></h3>
                    <p class="text-xs text-emerald-400/80 mt-1">
                        ထည့်သွင်းပြီးစီးမှု: <?= $stats['students'] ?> Students (5CS-1 to 5CS-10), <?= $stats['companies'] ?> Companies, <?= $stats['daily_logs'] ?> Daily Logs (မြန်မာလို), <?= $stats['weekly_reflections'] ?> Weekly Reflections & Evaluations.
                    </p>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="mb-6 p-4 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-300 flex items-start gap-3">
                <svg class="w-6 h-6 text-rose-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                <div>
                    <h3 class="font-bold text-rose-200">Error Occurred</h3>
                    <p class="text-xs text-rose-400/80 mt-1"><?= htmlspecialchars($error) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Current DB Status -->
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-8">
            <div class="bg-slate-800/80 border border-slate-700/60 p-4 rounded-xl text-center">
                <div class="text-2xl font-black text-indigo-400"><?= $count_users ?></div>
                <div class="text-xs text-slate-400 mt-1 font-medium">Total Users</div>
            </div>
            <div class="bg-slate-800/80 border border-slate-700/60 p-4 rounded-xl text-center">
                <div class="text-2xl font-black text-sky-400"><?= $count_students ?></div>
                <div class="text-xs text-slate-400 mt-1 font-medium">Students (5CS-1..10)</div>
            </div>
            <div class="bg-slate-800/80 border border-slate-700/60 p-4 rounded-xl text-center">
                <div class="text-2xl font-black text-emerald-400"><?= $count_logs ?></div>
                <div class="text-xs text-slate-400 mt-1 font-medium">Daily Logs (မြန်မာလို)</div>
            </div>
            <div class="bg-slate-800/80 border border-slate-700/60 p-4 rounded-xl text-center">
                <div class="text-2xl font-black text-purple-400"><?= $count_refs ?></div>
                <div class="text-xs text-slate-400 mt-1 font-medium">Reflections (မြန်မာလို)</div>
            </div>
            <div class="bg-slate-800/80 border border-slate-700/60 p-4 rounded-xl text-center col-span-2 sm:col-span-1">
                <div class="text-2xl font-black text-amber-400"><?= $count_companies ?></div>
                <div class="text-xs text-slate-400 mt-1 font-medium">Companies</div>
            </div>
        </div>

        <!-- Action Card -->
        <div class="bg-slate-800/90 border border-slate-700 rounded-2xl p-6 sm:p-8 shadow-xl mb-8">
            <h2 class="text-xl font-bold text-white mb-2">⚡ Data Generate ပြုလုပ်ရန် ခလုတ်</h2>
            <p class="text-sm text-slate-400 mb-6">
                Password: <code class="text-amber-300 font-mono bg-slate-900 px-2 py-0.5 rounded font-bold">P@ssword1</code> (All Accounts) | Names &amp; Signatures: <code class="text-indigo-300 font-mono bg-slate-900 px-2 py-0.5 rounded font-bold">English</code> | Logs &amp; Feedbacks: <code class="text-emerald-300 font-mono bg-slate-900 px-2 py-0.5 rounded font-bold">မြန်မာလို</code>
            </p>

            <form method="POST" class="flex flex-col sm:flex-row gap-4">
                <button type="submit" name="action" value="seed_fresh" 
                        onclick="return confirm('Data အားလုံးကို Name (English) + Logs/Reflections (မြန်မာလို) ဖြင့် ပြန်လည် ထည့်သွင်းမည် ဖြစ်ပါသည်။ သေချာပါသလား?')"
                        class="flex-1 py-4 px-6 rounded-xl bg-gradient-to-r from-indigo-500 via-purple-500 to-pink-500 text-white font-bold text-base shadow-lg shadow-indigo-500/25 hover:opacity-95 hover:scale-[1.01] transition-all flex items-center justify-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                    Clean & Generate Mock Data (English Names + Myanmar Logs)
                </button>
            </form>
        </div>

        <!-- Sample Accounts Credential Table -->
        <div class="bg-slate-800/90 border border-slate-700 rounded-2xl p-6 sm:p-8 shadow-xl">
            <h3 class="text-lg font-bold text-white mb-4 flex items-center gap-2">
                <svg class="w-5 h-5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path></svg>
                ခုံနံပါတ် အစဉ်လိုက် ကျောင်းသားများနှင့် Login အကောင့်များ စာရင်း
            </h3>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-700 text-slate-400">
                            <th class="pb-3 font-semibold">Roll No / Role</th>
                            <th class="pb-3 font-semibold">Name</th>
                            <th class="pb-3 font-semibold">Login Username / Gmail</th>
                            <th class="pb-3 font-semibold">Password</th>
                            <th class="pb-3 font-semibold">Company &amp; Supervisor</th>
                            <th class="pb-3 font-semibold">Data Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-700/60 text-slate-300">
                        <tr>
                            <td class="py-3"><span class="px-2 py-0.5 rounded-full bg-rose-500/20 text-rose-300 font-bold text-xs">Admin</span></td>
                            <td class="py-3 font-medium text-white">System Administrator</td>
                            <td class="py-3 font-mono text-indigo-300">admin <span class="text-slate-500">/</span> admin@gmail.com</td>
                            <td class="py-3 font-mono text-amber-300 font-bold">P@ssword1</td>
                            <td class="py-3 text-slate-400 text-xs">System Admin</td>
                            <td class="py-3 text-slate-400 text-xs">Full Control</td>
                        </tr>
                        <tr>
                            <td class="py-3"><span class="px-2 py-0.5 rounded-full bg-indigo-500/20 text-indigo-300 font-bold text-xs">Supervisor</span></td>
                            <td class="py-3 font-medium text-white">U Mya (Lecturer)</td>
                            <td class="py-3 font-mono text-indigo-300">umya.instructor@gmail.com</td>
                            <td class="py-3 font-mono text-amber-300 font-bold">P@ssword1</td>
                            <td class="py-3 text-slate-400 text-xs">CS Faculty</td>
                            <td class="py-3 text-slate-400 text-xs">Assigned 5CS-1, 5CS-2, 5CS-3, 5CS-10</td>
                        </tr>
                        <tr>
                            <td class="py-3"><span class="px-2 py-0.5 rounded-full bg-indigo-500/20 text-indigo-300 font-bold text-xs">Supervisor</span></td>
                            <td class="py-3 font-medium text-white">Dr. Aung Kyaw</td>
                            <td class="py-3 font-mono text-indigo-300">dr.aung <span class="text-slate-500">/</span> aungkyaw.supervisor@gmail.com</td>
                            <td class="py-3 font-mono text-amber-300 font-bold">P@ssword1</td>
                            <td class="py-3 text-slate-400 text-xs">CS Faculty</td>
                            <td class="py-3 text-slate-400 text-xs">Assigned 5CS-4, 5CS-5, 5CS-6</td>
                        </tr>
                        <tr>
                            <td class="py-3 font-mono font-bold text-emerald-400">5CS-1</td>
                            <td class="py-3 font-medium text-white">Maung Maung</td>
                            <td class="py-3 font-mono text-indigo-300">5CS-1 <span class="text-slate-500">/</span> mgmg.5cs1@gmail.com</td>
                            <td class="py-3 font-mono text-amber-300 font-bold">P@ssword1</td>
                            <td class="py-3 text-slate-400 text-xs">Soft Guide Tech (U Mya)</td>
                            <td class="py-3 text-emerald-400 font-semibold text-xs">၁၂ ပတ်စာ (မြန်မာလို အပြည့်အစုံ)</td>
                        </tr>
                        <tr>
                            <td class="py-3 font-mono font-bold text-emerald-400">5CS-2</td>
                            <td class="py-3 font-medium text-white">Su Su</td>
                            <td class="py-3 font-mono text-indigo-300">5CS-2 <span class="text-slate-500">/</span> susu.5cs2@gmail.com</td>
                            <td class="py-3 font-mono text-amber-300 font-bold">P@ssword1</td>
                            <td class="py-3 text-slate-400 text-xs">Ace Data Systems (U Mya)</td>
                            <td class="py-3 text-emerald-400 font-semibold text-xs">၁၂ ပတ်စာ (မြန်မာလို အပြည့်အစုံ)</td>
                        </tr>
                        <tr>
                            <td class="py-3 font-mono font-bold text-emerald-400">5CS-3</td>
                            <td class="py-3 font-medium text-white">Kyaw Kyaw</td>
                            <td class="py-3 font-mono text-indigo-300">5CS-3 <span class="text-slate-500">/</span> kyawkyaw.5cs3@gmail.com</td>
                            <td class="py-3 font-mono text-amber-300 font-bold">P@ssword1</td>
                            <td class="py-3 text-slate-400 text-xs">Dirace Myanmar (U Mya)</td>
                            <td class="py-3 text-sky-400 font-semibold text-xs">၁၀ ပတ်စာ (မြန်မာလို)</td>
                        </tr>
                        <tr>
                            <td class="py-3 font-mono font-bold text-emerald-400">5CS-4</td>
                            <td class="py-3 font-medium text-white">Min Min Aung</td>
                            <td class="py-3 font-mono text-indigo-300">5CS-4 <span class="text-slate-500">/</span> minmin.5cs4@gmail.com</td>
                            <td class="py-3 font-mono text-amber-300 font-bold">P@ssword1</td>
                            <td class="py-3 text-slate-400 text-xs">Nexlabs (Dr. Aung Kyaw)</td>
                            <td class="py-3 text-emerald-400 font-semibold text-xs">၁၂ ပတ်စာ (မြန်မာလို အပြည့်အစုံ)</td>
                        </tr>
                        <tr>
                            <td class="py-3 font-mono font-bold text-emerald-400">5CS-5</td>
                            <td class="py-3 font-medium text-white">Aye Aye Mon</td>
                            <td class="py-3 font-mono text-indigo-300">5CS-5 <span class="text-slate-500">/</span> ayeaye.5cs5@gmail.com</td>
                            <td class="py-3 font-mono text-amber-300 font-bold">P@ssword1</td>
                            <td class="py-3 text-slate-400 text-xs">Ace Data (Dr. Aung Kyaw)</td>
                            <td class="py-3 text-sky-400 text-xs">၈ ပတ်စာ (မြန်မာလို)</td>
                        </tr>
                        <tr>
                            <td class="py-3 font-mono font-bold text-emerald-400">5CS-6</td>
                            <td class="py-3 font-medium text-white">Htet Htet Lin</td>
                            <td class="py-3 font-mono text-indigo-300">5CS-6 <span class="text-slate-500">/</span> htethtet.5cs6@gmail.com</td>
                            <td class="py-3 font-mono text-amber-300 font-bold">P@ssword1</td>
                            <td class="py-3 text-slate-400 text-xs">Soft Guide (Dr. Aung Kyaw)</td>
                            <td class="py-3 text-sky-400 text-xs">၆ ပတ်စာ (မြန်မာလို)</td>
                        </tr>
                        <tr>
                            <td class="py-3 font-mono font-bold text-emerald-400">5CS-7</td>
                            <td class="py-3 font-medium text-white">Aung Myint Myat</td>
                            <td class="py-3 font-mono text-indigo-300">5CS-7 <span class="text-slate-500">/</span> aungmyat.5cs7@gmail.com</td>
                            <td class="py-3 font-mono text-amber-300 font-bold">P@ssword1</td>
                            <td class="py-3 text-slate-400 text-xs">KBZ Bank (Dr. Su Su)</td>
                            <td class="py-3 text-amber-400 text-xs">၄ ပတ်စာ (မြန်မာလို)</td>
                        </tr>
                        <tr>
                            <td class="py-3 font-mono font-bold text-emerald-400">5CS-8</td>
                            <td class="py-3 font-medium text-white">Phyu Phyu Thin</td>
                            <td class="py-3 font-mono text-indigo-300">5CS-8 <span class="text-slate-500">/</span> phyuphyu.5cs8@gmail.com</td>
                            <td class="py-3 font-mono text-amber-300 font-bold">P@ssword1</td>
                            <td class="py-3 text-slate-400 text-xs">Nexlabs (Dr. Su Su)</td>
                            <td class="py-3 text-amber-400 text-xs">၂ ပတ်စာ (မြန်မာလို)</td>
                        </tr>
                        <tr>
                            <td class="py-3 font-mono font-bold text-emerald-400">5CS-9</td>
                            <td class="py-3 font-medium text-white">Zin Min Thu</td>
                            <td class="py-3 font-mono text-indigo-300">5CS-9 <span class="text-slate-500">/</span> zinmin.5cs9@gmail.com</td>
                            <td class="py-3 font-mono text-amber-300 font-bold">P@ssword1</td>
                            <td class="py-3 text-slate-400 text-xs">Dirace Myanmar (Dr. Su Su)</td>
                            <td class="py-3 text-amber-400 text-xs">၁ ပတ်စာ (မြန်မာလို)</td>
                        </tr>
                        <tr>
                            <td class="py-3 font-mono font-bold text-emerald-400">5CS-10</td>
                            <td class="py-3 font-medium text-white">Thurein Tun</td>
                            <td class="py-3 font-mono text-indigo-300">5CS-10 <span class="text-slate-500">/</span> thurein.5cs10@gmail.com</td>
                            <td class="py-3 font-mono text-amber-300 font-bold">P@ssword1</td>
                            <td class="py-3 text-slate-400 text-xs">Soft Guide Tech (U Mya)</td>
                            <td class="py-3 text-slate-400 text-xs">Fresh Student (ကိုယ်တိုင်ထည့်ရန်)</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-6 pt-4 border-t border-slate-700/60 flex items-center justify-between">
                <a href="../login.php" class="inline-flex items-center gap-2 text-indigo-400 hover:text-indigo-300 font-bold text-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path></svg>
                    Go to Login Page
                </a>
                <span class="text-xs text-slate-500">InternReport Management System &copy; 2026</span>
            </div>
        </div>
    </div>
</body>
</html>
