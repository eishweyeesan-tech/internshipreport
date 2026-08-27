<?php
/**
 * Database Seeder for Academic Year 2024-2025 (Myanmar Language Edition)
 * InternReport Management System
 * 
 * Period: May 5, 2025 to July 31, 2025 (13 Weeks)
 * Students: 10 Students with Full Realistic Data in Myanmar Language (မြန်မာဘာသာ)
 * - Daily Logs (Tasks, Tools, Skills, Absences in Myanmar)
 * - Weekly Reflections (What, How, Why in Myanmar)
 * - Instructor Evaluations (Grades, Signatures, Revision Requests in Myanmar)
 * - Supervisor Weekly Evaluations (A/B/C Grades, Comments in Myanmar)
 */

require_once __DIR__ . '/../config/db.php';

set_time_limit(300);
$conn->set_charset('utf8mb4');

echo "====================================================\n";
echo "Starting Mock Data Seeder in Myanmar Language (13 Weeks)...\n";
echo "====================================================\n";

// 1. Get or verify Academic Year 2024-2025
$ay_label = '2024-2025';
$stmt = $conn->prepare("SELECT id FROM academic_years WHERE year_label = ? LIMIT 1");
$stmt->bind_param("s", $ay_label);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $academic_year_id = (int)$row['id'];
} else {
    $conn->query("INSERT INTO academic_years (year_label, start_date, end_date, is_current, status) VALUES ('2024-2025', '2024-09-01', '2025-08-31', 0, 'Active')");
    $academic_year_id = $conn->insert_id;
}

// 2. Fetch existing supervisors and companies
$supervisors = [];
$res = $conn->query("SELECT id, username, email FROM users WHERE role = 'supervisor' ORDER BY id ASC");
while ($r = $res->fetch_assoc()) {
    $supervisors[] = $r;
}

$companies = [];
$res = $conn->query("SELECT id, company_name, contact_person, contact_email, contact_phone FROM companies ORDER BY id ASC");
while ($r = $res->fetch_assoc()) {
    $companies[] = $r;
}

// Clean previous 2024-2025 students
$conn->query("DELETE FROM users WHERE academic_year = '2024-2025' AND role = 'student'");
echo "Cleaned previous 2024-2025 student records.\n";

// 3. Define 10 Students for 2024-2025
$students_data = [
    [
        'username'        => '5CS-1',
        'email'           => 'aungkhant.2024@gmail.com',
        'full_name'       => 'Aung Khant Min',
        'student_roll'    => '5CS-1',
        'major'           => 'Computer Science',
        'phone'           => '09-450123891',
        'job_role'        => 'Backend PHP/Laravel Developer',
        'company_idx'     => 0, // Soft Guide Technology
        'supervisor_idx'  => 0, // U Mya
        'instructor_name' => 'U Thurein Lin (Senior Dev)',
        'instructor_email'=> 'thurein.softguide@gmail.com',
        'instructor_phone'=> '09-798112233',
        'tech_stack'      => 'PHP 8.2, Laravel 11, MySQL, REST API, Redis, Postman',
    ],
    [
        'username'        => '5CS-2',
        'email'           => 'hsuhsu.2024@gmail.com',
        'full_name'       => 'Hsu Hsu Wai',
        'student_roll'    => '5CS-2',
        'major'           => 'Computer Science',
        'phone'           => '09-781293401',
        'job_role'        => 'Frontend React Developer',
        'company_idx'     => 1, // Nexlabs Myanmar
        'supervisor_idx'  => 1, // Dr. Aung Kyaw
        'instructor_name' => 'Daw May Thet (Lead Frontend)',
        'instructor_email'=> 'maythet.nexlabs@gmail.com',
        'instructor_phone'=> '09-421009988',
        'tech_stack'      => 'React 18, TypeScript, Tailwind CSS, Redux Toolkit, Vite, Jest',
    ],
    [
        'username'        => '5CS-3',
        'email'           => 'kaungsithu.2024@gmail.com',
        'full_name'       => 'Kaung Si Thu',
        'student_roll'    => '5CS-3',
        'major'           => 'Computer Science',
        'phone'           => '09-250912834',
        'job_role'        => 'Full-Stack Web Developer',
        'company_idx'     => 2, // Ace Data Systems
        'supervisor_idx'  => 2, // Dr. Su Su Hlaing
        'instructor_name' => 'U Hein Htet (Tech Lead)',
        'instructor_email'=> 'heinhtet.acedata@gmail.com',
        'instructor_phone'=> '09-976554433',
        'tech_stack'      => 'Node.js, Express, Vue.js 3, PostgreSQL, Docker, Git',
    ],
    [
        'username'        => '5CS-4',
        'email'           => 'eiphway.2024@gmail.com',
        'full_name'       => 'Ei Phway Phway',
        'student_roll'    => '5CS-4',
        'major'           => 'Computer Technology',
        'phone'           => '09-965412309',
        'job_role'        => 'QA & Test Automation Engineer',
        'company_idx'     => 3, // Dirace Myanmar
        'supervisor_idx'  => 0, // U Mya
        'instructor_name' => 'Daw Nilar Win (QA Lead)',
        'instructor_email'=> 'nilarwin.dirace@gmail.com',
        'instructor_phone'=> '09-261199445',
        'tech_stack'      => 'Selenium, Cypress, Postman API Testing, JIRA, Python pytest',
    ],
    [
        'username'        => '5CS-5',
        'email'           => 'linnhtet.2024@gmail.com',
        'full_name'       => 'Linn Htet Aung',
        'student_roll'    => '5CS-5',
        'major'           => 'Computer Technology',
        'phone'           => '09-778812903',
        'job_role'        => 'Database & Core Banking Support Intern',
        'company_idx'     => 4, // KBZ Bank
        'supervisor_idx'  => 1, // Dr. Aung Kyaw
        'instructor_name' => 'U Kyaw Zayar (Database Administrator)',
        'instructor_email'=> 'kyawzayar.kbztech@gmail.com',
        'instructor_phone'=> '09-445566778',
        'tech_stack'      => 'Oracle DB, MySQL, SQL Performance Tuning, Linux Bash, Data Backup',
    ],
    [
        'username'        => '5CS-6',
        'email'           => 'moethiri.2024@gmail.com',
        'full_name'       => 'Moe Thiri San',
        'student_roll'    => '5CS-6',
        'major'           => 'Computer Science',
        'phone'           => '09-420019283',
        'job_role'        => 'UI/UX Designer & Frontend Assistant',
        'company_idx'     => 1, // Nexlabs Myanmar
        'supervisor_idx'  => 2, // Dr. Su Su Hlaing
        'instructor_name' => 'Daw May Thet (Lead Frontend)',
        'instructor_email'=> 'maythet.nexlabs@gmail.com',
        'instructor_phone'=> '09-421009988',
        'tech_stack'      => 'Figma, Adobe XD, HTML5/CSS3, Tailwind CSS, Responsive Design',
    ],
    [
        'username'        => '5CS-7',
        'email'           => 'sithuwin.2024@gmail.com',
        'full_name'       => 'Si Thu Win',
        'student_roll'    => '5CS-7',
        'major'           => 'Computer Science',
        'phone'           => '09-254499120',
        'job_role'        => 'Mobile Application Developer (Flutter)',
        'company_idx'     => 0, // Soft Guide Technology
        'supervisor_idx'  => 0, // U Mya
        'instructor_name' => 'U Thurein Lin (Senior Dev)',
        'instructor_email'=> 'thurein.softguide@gmail.com',
        'instructor_phone'=> '09-798112233',
        'tech_stack'      => 'Flutter, Dart, Firebase Authentication, SQLite, Provider/Bloc',
    ],
    [
        'username'        => '5CS-8',
        'email'           => 'yamin.2024@gmail.com',
        'full_name'       => 'Yamin Oo',
        'student_roll'    => '5CS-8',
        'major'           => 'Computer Technology',
        'phone'           => '09-790182345',
        'job_role'        => 'Cloud Infrastructure & DevOps Intern',
        'company_idx'     => 2, // Ace Data Systems
        'supervisor_idx'  => 1, // Dr. Aung Kyaw
        'instructor_name' => 'U Hein Htet (Tech Lead)',
        'instructor_email'=> 'heinhtet.acedata@gmail.com',
        'instructor_phone'=> '09-976554433',
        'tech_stack'      => 'AWS EC2/S3, Docker, CI/CD GitHub Actions, Linux Nginx, SSL',
    ],
    [
        'username'        => '5CS-9',
        'email'           => 'thawdarmaung.2024@gmail.com',
        'full_name'       => 'Thaw Dar Maung',
        'student_roll'    => '5CS-9',
        'major'           => 'Computer Technology',
        'phone'           => '09-459981234',
        'job_role'        => 'Cyber Security & Network Support',
        'company_idx'     => 4, // KBZ Bank
        'supervisor_idx'  => 2, // Dr. Su Su Hlaing
        'instructor_name' => 'U Kyaw Zayar (Database Administrator)',
        'instructor_email'=> 'kyawzayar.kbztech@gmail.com',
        'instructor_phone'=> '09-445566778',
        'tech_stack'      => 'Wireshark, Cisco Packet Tracer, Firewall Config, Vulnerability Scan',
    ],
    [
        'username'        => '5CS-10',
        'email'           => 'wathanhtun.2024@gmail.com',
        'full_name'       => 'Wa Than Htun',
        'student_roll'    => '5CS-10',
        'major'           => 'Computer Science',
        'phone'           => '09-981234765',
        'job_role'        => 'Full-Stack Web Developer (PHP/JavaScript)',
        'company_idx'     => 3, // Dirace Myanmar
        'supervisor_idx'  => 0, // U Mya
        'instructor_name' => 'Daw Nilar Win (QA Lead)',
        'instructor_email'=> 'nilarwin.dirace@gmail.com',
        'instructor_phone'=> '09-261199445',
        'tech_stack'      => 'PHP 8, MySQLi, JavaScript ES6, Tailwind CSS, AJAX, MVC Architecture',
    ],
];

$password_hash = password_hash('password1234', PASSWORD_DEFAULT);

// 13 Weeks definitions (May 5, 2025 to July 31, 2025)
$weeks_schedule = [
    1  => ['start' => '2025-05-05', 'end' => '2025-05-09'],
    2  => ['start' => '2025-05-12', 'end' => '2025-05-16'],
    3  => ['start' => '2025-05-19', 'end' => '2025-05-23'],
    4  => ['start' => '2025-05-26', 'end' => '2025-05-30'],
    5  => ['start' => '2025-06-02', 'end' => '2025-06-06'],
    6  => ['start' => '2025-06-09', 'end' => '2025-06-13'],
    7  => ['start' => '2025-06-16', 'end' => '2025-06-20'],
    8  => ['start' => '2025-06-23', 'end' => '2025-06-27'],
    9  => ['start' => '2025-06-30', 'end' => '2025-07-04'],
    10 => ['start' => '2025-07-07', 'end' => '2025-07-11'],
    11 => ['start' => '2025-07-14', 'end' => '2025-07-18'],
    12 => ['start' => '2025-07-21', 'end' => '2025-07-25'],
    13 => ['start' => '2025-07-28', 'end' => '2025-07-31'],
];

// Rich Myanmar Language Curricula for each role (13 Weeks)
$weekly_curriculum_mm = [
    'Backend PHP/Laravel Developer' => [
        1  => [
            'ခေါင်းစဉ်' => 'Orientation နှင့် Development Environment စတင်ပြင်ဆင်ခြင်း',
            'လုပ်ဆောင်ချက်' => 'ကုမ္ပဏီ၏ လုပ်ငန်းခွင် စည်းမျဉ်းများကို လေ့လာခြင်း၊ PHP 8.2, Composer, Docker နှင့် Git repository များကို စနစ်တကျ setup ပြုလုပ်ပြီး စတင်လေ့လာခဲ့သည်။',
            'ကျွမ်းကျင်မှု' => 'Git version control, local server configuration, team onboarding workflow.',
            'အပတ်စဉ်သုံးသပ်ချက်_what' => 'ဤပထမပတ်တွင် ကုမ္ပဏီ၏ လုပ်ငန်းခွင် စည်းမျဉ်းများ၊ ပရောဂျက်တည်ဆောက်ပုံနှင့် လိုအပ်သော Development Tools (PHP, Composer, Git, Docker) များကို အောင်မြင်စွာ setup ပြုလုပ်နိုင်ခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_how' => 'Senior Developer ဖြစ်သူ ဦးသူရိန်လင်း၏ လမ်းညွှန်မှုဖြင့် အဖွဲ့လိုက် Git branching workflow နှင့် ကုမ္ပဏီ codebase ကို စနစ်တကျ လေ့လာဆန်းစစ်ခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_why' => 'လက်တွေ့ Project စတင်ရာတွင် Development ပတ်ဝန်းကျင် တူညီမှုရှိစေရန်နှင့် နောင်လာမည့် sprint များတွင် ချောမွေ့စွာ ပူးပေါင်းဆောင်ရွက်နိုင်ရန် အလွန်အရေးပါသောကြောင့် ဖြစ်ပါသည်။'
        ],
        2  => [
            'ခေါင်းစဉ်' => 'Database Schema ရေးဆွဲခြင်းနှင့် Migration ဖန်တီးခြင်း',
            'လုပ်ဆောင်ချက်' => 'စနစ်အတွက် လိုအပ်သော Relational Database Table များ၊ Eloquent ORM Models များနှင့် Database Migrations များကို ရေးသားတည်ဆောက်ခဲ့သည်။',
            'ကျွမ်းကျင်မှု' => 'Database normalization (3NF), Eloquent ORM relationships, MySQL indexing.',
            'အပတ်စဉ်သုံးသပ်ချက်_what' => 'စနစ်အတွက် လိုအပ်သော Users, Student Profiles, Daily Logs နှင့် Evaluations ဇယားများအတွက် Database Migration များနှင့် Model Relationships များကို တည်ဆောက်ခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_how' => 'Laravel Migration နှင့် Seeder စနစ်ကို အသုံးပြု၍ Database Normalization ပြည့်စုံအောင် ရေးဆွဲပြီး Foreign Keys များကို သတ်မှတ်ခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_why' => 'စနစ်အတွင်းရှိ Data များ တိကျခိုင်မာစေရန်နှင့် Relational Query များ လုပ်ဆောင်ရာတွင် စွမ်းဆောင်ရည် အမြင့်မားဆုံး ရရှိစေရန် ဖြစ်ပါသည်။'
        ],
        3  => [
            'ခေါင်းစဉ်' => 'Authentication API နှင့် Token အခြေပြု လုံခြုံရေး တည်ဆောက်ခြင်း',
            'လုပ်ဆောင်ချက်' => 'RESTful API များအတွက် User Registration, Login, Password Reset နှင့် Sanctum/JWT token အခြေပြု authentication စနစ်များကို ရေးသားခဲ့သည်။',
            'ကျွမ်းကျင်မှု' => 'REST API design, JWT/Sanctum authentication, password hashing (Bcrypt), Postman API testing.',
            'အပတ်စဉ်သုံးသပ်ချက်_what' => 'လုံခြုံစိတ်ချရသော User Authentication API endpoints များနှင့် Password Reset စနစ်ကို အောင်မြင်စွာ ရေးသားပြီးမြောက်ခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_how' => 'Laravel Sanctum ကို အသုံးပြု၍ Token-based Auth စနစ်ကို ရေးသားခဲ့ပြီး Postman ဖြင့် အောင်မြင်စွာ Test ပြုလုပ်စစ်ဆေးခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_why' => 'စနစ်အသုံးပြုသူများ၏ အချက်အလက်များ လုံခြုံမှုရှိစေရန်နှင့် Frontend မှ API ကို အဆင်ပြေပြေ ချိတ်ဆက်အသုံးပြုနိုင်ရန် ဖြစ်ပါသည်။'
        ],
        4  => [
            'ခေါင်းစဉ်' => 'Daily Logs နှင့် Weekly Reflection CRUD Operations ရေးသားခြင်း',
            'လုပ်ဆောင်ချက်' => 'ကျောင်းသားများ နေ့စဉ်မှတ်တမ်းနှင့် အပတ်စဉ် သုံးသပ်ချက်များ ရေးသားတင်ပြနိုင်သည့် Controllers, Services နှင့် Business Logic များကို တည်ဆောက်ခဲ့သည်။',
            'ကျွမ်းကျင်မှု' => 'MVC architecture, CRUD API development, duration calculation logic, input validation.',
            'အပတ်စဉ်သုံးသပ်ချက်_what' => 'ကျောင်းသားများ နေ့စဉ်အလုပ်ချိန်၊ ပြုလုပ်ခဲ့သော တာဝန်များနှင့် အပတ်စဉ် သုံးသပ်ချက်များ တင်ပြနိုင်သည့် CRUD Controller များကို ရေးသားခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_how' => 'Single Responsibility Principle ကို လိုက်နာကာ Service Layer ခွဲထုတ်၍ သန့်ရှင်းသော Code Structure ဖြင့် ရေးသားခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_why' => 'Internship စနစ်၏ အဓိက အသက်သွေးကြောဖြစ်သော မှတ်တမ်းတင်ခြင်း လုပ်ငန်းစဉ်ကို တိကျမြန်ဆန်စွာ ဆောင်ရွက်နိုင်ရန် ဖြစ်ပါသည်။'
        ],
        5  => [
            'ခေါင်းစဉ်' => 'Form Request Validation နှင့် Custom Error Middleware ဖန်တီးခြင်း',
            'လုပ်ဆောင်ချက်' => 'အချက်အလက် ထည့်သွင်းမှုများတွင် အမှားအယွင်း မရှိစေရန် FormRequest validation rules များနှင့် Custom JSON error response handling များကို ရေးသားခဲ့သည်။',
            'ကျွမ်းကျင်မှု' => 'Data validation, custom middleware, exception handling, clean JSON API responses.',
            'အပတ်စဉ်သုံးသပ်ချက်_what' => 'စနစ်အတွင်းသို့ မမှန်ကန်သော Data များ မဝင်ရောက်နိုင်စေရန် Form Request Validation များနှင့် Global Exception Middleware ကို ရေးသားခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_how' => 'Laravel Custom Rules များနှင့် Middleware များကို အသုံးပြု၍ Business Logic အလိုက် တင်းကျပ်သော စစ်ဆေးမှုများ ပြုလုပ်ခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_why' => 'Database Data Integrity ကို ကာကွယ်ရန်နှင့် Client ဘက်သို့ နားလည်လွယ်သော Error Message များ ပြန်လည်ပေးပို့နိုင်ရန် ဖြစ်ပါသည်။'
        ],
        6  => [
            'ခေါင်းစဉ်' => 'Secure File Upload နှင့် Image/Avatar Processing စနစ်',
            'လုပ်ဆောင်ချက်' => 'Profile ဓာတ်ပုံများနှင့် အစီရင်ခံစာ ပူးတွဲဖိုင်များကို လုံခြုံစွာ upload တင်နိုင်ရန် MIME-type validation, size restriction နှင့် file optimization များကို ရေးသားခဲ့သည်။',
            'ကျွမ်းကျင်မှု' => 'File upload security, MIME verification, image compression, storage driver management.',
            'အပတ်စဉ်သုံးသပ်ချက်_what' => 'ကျောင်းသားများနှင့် User များအတွက် Profile ပုံများနှင့် PDF File များကို လုံခြုံစွာ Upload တင်နိုင်သော File Processing Service ကို ရေးသားခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_how' => 'File Extension အပြင် MIME Type ကိုပါ Server ဘက်မှ အထူးစစ်ဆေးပြီး Random Hash နာမည်များဖြင့် သိမ်းဆည်းနိုင်အောင် ရေးသားခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_why' => 'Malicious File များ Upload တင်ခံရခြင်းမှ ကာကွယ်နိုင်ရန်နှင့် Server Storage ကို စနစ်တကျ စီမံခန့်ခွဲနိုင်ရန် ဖြစ်ပါသည်။'
        ],
        7  => [
            'ခေါင်းစဉ်' => 'PHPMailer နှင့် SMTP Email Notification Service ချိတ်ဆက်ခြင်း',
            'လုပ်ဆောင်ချက်' => 'Report အတည်ပြုခြင်း၊ ပြန်ပြင်ရန် တောင်းဆိုခြင်းနှင့် အပတ်စဉ် သတိပေးချက်များ ပေးပို့နိုင်ရန် SMTP Email Helper စနစ်ကို တည်ဆောက်ခဲ့သည်။',
            'ကျွမ်းကျင်မှု' => 'SMTP mail integration, PHPMailer configuration, asynchronous email queues, HTML email templates.',
            'အပတ်စဉ်သုံးသပ်ချက်_what' => 'စနစ်မှ အလိုအလျောက် Email ပေးပို့နိုင်သော PHPMailer / SMTP Mail Service ကို Template ဒီဇိုင်းများနှင့်တကွ အောင်မြင်စွာ ချိတ်ဆက်ခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_how' => 'SMTP Configuration များကို လုံခြုံစွာ ချိတ်ဆက်ပြီး HTML Email Layout များဖြင့် အကြောင်းကြားစာ ပေးပို့နိုင်အောင် ရေးသားခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_why' => 'ကျောင်းသားနှင့် Supervisor များအကြား Report အခြေအနေများကို အချိန်နှင့်တပြေးညီ အီးမေးလ်ဖြင့် သိရှိနိုင်စေရန် ဖြစ်ပါသည်။'
        ],
        8  => [
            'ခေါင်းစဉ်' => 'Database Query Optimization နှင့် Redis Caching စနစ်',
            'လုပ်ဆောင်ချက်' => 'စနစ်တစ်ခုလုံး၏ Query Response Time မြန်ဆန်စေရန် Database Indexing ပြုလုပ်ခြင်းနှင့် မကြာခဏ အသုံးပြုသော Dashboard Stats များကို Redis Cache သုံး၍ Optimize လုပ်ခဲ့သည်။',
            'ကျွမ်းကျင်မှု' => 'Query optimization, MySQL indexing, Redis in-memory cache, performance tuning.',
            'အပတ်စဉ်သုံးသပ်ချက်_what' => 'စနစ်၏ Dashboard ဒေတာများနှင့် စာရင်းများကို မြန်ဆန်စွာ ဖော်ပြနိုင်ရန် Database Queries များကို Optimize လုပ်ပြီး Caching စနစ် ထည့်သွင်းခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_how' => 'EXPLAIN Query ဖြင့် Bottleneck များကို စစ်ဆေးကာ လိုအပ်သော Index များ ထည့်သွင်းပြီး မကြာခဏ ခေါ်ယူသော ဒေတာများကို Cache သိမ်းဆည်းခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_why' => 'အသုံးပြုသူ များပြားလာချိန်တွင် Server Load နည်းပါးစေရန်နှင့် Page Load Time ကို ၄၅% ကျော် လျှော့ချနိုင်စေရန် ဖြစ်ပါသည်။'
        ],
        9  => [
            'ခေါင်းစဉ်' => 'Automated Unit Testing နှင့် API Feature Testing ရေးသားခြင်း',
            'လုပ်ဆောင်ချက်' => 'ရေးသားထားသော API များနှင့် Business Logic များ မှန်ကန်မှုရှိစေရန် PHPUnit ဖြင့် Automated Test Cases များ ရေးသား စစ်ဆေးခဲ့သည်။',
            'ကျွမ်းကျင်မှု' => 'Unit testing, Integration testing, PHPUnit, test-driven methodology, bug fixing.',
            'အပတ်စဉ်သုံးသပ်ချက်_what' => 'Authentication, Daily Log CRUD နှင့် Instructor Grading API များအတွက် PHPUnit Automated Test Cases ၃၀ ကျော် ရေးသား စစ်ဆေးခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_how' => 'Mock Data များနှင့် Database Transactions များကို အသုံးပြု၍ Feature Test Suite ကို အောင်မြင်စွာ Run စစ်ဆေးခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_why' => 'Code ပြင်ဆင်သည့်အခါ မလိုလားအပ်သော Side Effects နှင့် Regressions များ မဖြစ်ပေါ်စေရန် စိတ်ချရမှု ရရှိစေရန် ဖြစ်ပါသည်။'
        ],
        10 => [
            'ခေါင်းစဉ်' => 'Role-Based Access Control (RBAC) နှင့် လုံခြုံရေးမူဝါဒများ ထည့်သွင်းခြင်း',
            'လုပ်ဆောင်ချက်' => 'Admin, Student, Supervisor, Instructor ဟူသော အခန်းကဏ္ဍ (၄) ခုအလိုက် သီးသန့် ခွင့်ပြုချက်များ (Permissions) ကို Middleware Guards ဖြင့် တင်းကျပ်စွာ သတ်မှတ်ခဲ့သည်။',
            'ကျွမ်းကျင်မှု' => 'RBAC security policy, authorization middleware, route guards, security audit.',
            'အပတ်စဉ်သုံးသပ်ချက်_what' => 'User Role (၄) ခုအကြား ခွင့်ပြုချက်မဲ့ ဝင်ရောက်မှုများကို တားဆီးရန် တင်းကျပ်သော RBAC Middleware နှင့် Security Guards များကို ရေးသားခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_how' => 'Session နှင့် Token တွင် Role စစ်ဆေးမှုကို ထည့်သွင်းပြီး Page/API တစ်ခုချင်းစီအလိုက် Authorization Checks များကို ပြုလုပ်ခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_why' => 'စနစ်အတွင်းရှိ Sensitive Data များ (အမှတ်ပေးမှု၊ Admin အချက်အလက်များ) ကို သက်ဆိုင်သူမှအပ အခြားသူများ မမြင်တွေ့စေရန် ဖြစ်ပါသည်။'
        ],
        11 => [
            'ခေါင်းစဉ်' => 'Printable PDF Report Generation စနစ် တည်ဆောက်ခြင်း',
            'လုပ်ဆောင်ချက်' => 'တက္ကသိုလ်သို့ တင်ပြရမည့် ၁၂ ပတ်စာ အပတ်စဉ် မှတ်တမ်းနှင့် အမှတ်ပေးဇယားများကို စနစ်တကျ Print ထုတ်ယူနိုင်သည့် PDF Export Template ရေးသားခဲ့သည်။',
            'ကျွမ်းကျင်မှု' => 'DOMPDF/TCPDF integration, printable CSS styling, multi-page layout design.',
            'အပတ်စဉ်သုံးသပ်ချက်_what' => 'တက္ကသိုလ်သို့ တင်သွင်းရမည့် Standard Format အတိုင်း အပတ်စဉ် အစီရင်ခံစာနှင့် Final Report PDF များကို Print ထုတ်ယူနိုင်သည့် Layout ကို ရေးသားခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_how' => 'Print CSS (@media print) နှင့် PDF Generator ကို အသုံးပြု၍ တက္ကသိုလ် Header၊ ကျောင်းသား အချက်အလက်၊ Instructor လက်မှတ်များ ပါဝင်အောင် ရေးဆွဲခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_why' => 'ကျောင်းသားများအနေဖြင့် စာရွက်စာတမ်း အထောက်အထားများကို တက္ကသိုလ်သို့ တရားဝင် လွယ်ကူစွာ တင်ပြနိုင်စေရန် ဖြစ်ပါသည်။'
        ],
        12 => [
            'ခေါင်းစဉ်' => 'Production Server Deployment နှင့် Staging Test များ ဆောင်ရွက်ခြင်း',
            'လုပ်ဆောင်ချက်' => 'Linux Nginx Web Server ပေါ်တွင် စနစ်ကို Deploy ပြုလုပ်ခြင်း၊ Environment Variables ချိန်ညှိခြင်းနှင့် SSL Certificate တပ်ဆင်ခြင်းများ ပြုလုပ်ခဲ့သည်။',
            'ကျွမ်းကျင်မှု' => 'Linux server administration, Nginx configuration, SSL certificate setup, production debugging.',
            'အပတ်စဉ်သုံးသပ်ချက်_what' => 'စနစ်ကို Production / Staging Server ပေါ်သို့ အောင်မြင်စွာ Deploy ပြုလုပ်ပြီး HTTPS / SSL လုံခြုံရေးများကို ချိန်ညှိတပ်ဆင်ခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_how' => 'Nginx Reverse Proxy နှင့် PHP-FPM ကို ချိတ်ဆက်ကာ Production Database Migration များကို စနစ်တကျ Run ခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_why' => 'စနစ်ကို အသုံးပြုသူ အားလုံး အင်တာနက်ပေါ်မှ လုံခြုံစိတ်ချစွာ စတင် အသုံးပြုနိုင်စေရန် ဖြစ်ပါသည်။'
        ],
        13 => [
            'ခေါင်းစဉ်' => 'Final Project Presentation နှင့် စနစ်လွှဲပြောင်းပေးအပ်ခြင်း',
            'လုပ်ဆောင်ချက်' => 'Internship ကာလအတွင်း ရေးသားခဲ့သော Backend Code များကို Documentation အပြည့်အစုံ ရေးသားကာ Team Lead ထံသို့ ပရောဂျက်တင်ပြ အပ်နှံခဲ့သည်။',
            'ကျွမ်းကျင်မှု' => 'Technical documentation, API specifications, final presentation, code handover.',
            'အပတ်စဉ်သုံးသပ်ချက်_what' => '၁၃ ပတ်စာ Internship ပရောဂျက်တစ်ခုလုံးကို အောင်မြင်စွာ ပြီးမြောက်ခဲ့ပြီး Technical Documentation နှင့် API Specs များကို Team ထံ လွှဲပြောင်းပေးအပ်ခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_how' => 'စနစ်၏ စွမ်းဆောင်ရည်များနှင့် ရလဒ်များကို Presentation Slide များနှင့်တကွ Live Demo ပြသကာ အောင်မြင်စွာ တင်ပြခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_why' => 'Internship သင်ယူမှု ရည်မှန်းချက်များ အားလုံး ပြည့်မီကြောင်း သက်သေပြနိုင်ခဲ့ပြီး လုပ်ငန်းခွင် လက်တွေ့ အတွေ့အကြုံကောင်းများ အပြည့်အဝ ရရှိခဲ့သောကြောင့် ဖြစ်ပါသည်။'
        ],
    ],
    'Frontend React Developer' => [
        1  => [
            'ခေါင်းစဉ်' => 'Frontend Architecture နှင့် UI Component Design Guidelines လေ့လာခြင်း',
            'လုပ်ဆောင်ချက်' => 'Vite + React ပတ်ဝန်းကျင်ကို စနစ်တကျ setup လုပ်ခြင်း၊ Tailwind CSS နှင့် ကုမ္ပဏီ၏ UI Design System စည်းမျဉ်းများကို စတင်လေ့လာခဲ့သည်။',
            'ကျွမ်းကျင်မှု' => 'React 18, Vite build tool, Tailwind CSS design system, clean folder structuring.',
            'အပတ်စဉ်သုံးသပ်ချက်_what' => 'React 18 နှင့် Tailwind CSS ကို အသုံးပြု၍ Frontend Project တည်ဆောက်ပုံ အခြေခံကို အောင်မြင်စွာ စတင် setup ပြုလုပ်နိုင်ခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_how' => 'Lead Frontend Developer ဒေါ်မေသက်၏ လမ်းညွှန်မှုဖြင့် Component Architecture များနှင့် UI Guidelines များကို လေ့လာဆန်းစစ်ခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_why' => 'Frontend အစိတ်အပိုင်းများ ရေးသားရာတွင် Reusable ဖြစ်ပြီး စနစ်တကျ တစ်ပြေးညီ လှပစေရန် ဖြစ်ပါသည်။'
        ],
        2  => [
            'ခေါင်းစဉ်' => 'Reusable UI Components တည်ဆောက်ခြင်း (Buttons, Modals, Badges)',
            'လုပ်ဆောင်ချက်' => 'စနစ်အတွင်း ထပ်ခါတလဲလဲ အသုံးပြုမည့် Buttons, Input Fields, Modal Dialogs, Alert Boxes များကို Reusable Components များအဖြစ် ရေးသားခဲ့သည်။',
            'ကျွမ်းကျင်မှု' => 'Component-driven development, atomic design, props & state management.',
            'အပတ်စဉ်သုံးသပ်ချက်_what' => 'စနစ်တစ်ခုလုံးတွင် အသုံးပြုမည့် Buttons, Form Controls, Badges နှင့် Modal Components များကို ရေးသားပြီးစီးခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_how' => 'Tailwind CSS utility classes များကို အသုံးပြုကာ Accessible ဖြစ်ပြီး Responsive ပြေပြစ်သော UI Atoms & Molecules များကို ဖန်တီးခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_why' => 'Code ပြန်လည်အသုံးပြုနိုင်မှု မြင့်မားစေရန်နှင့် UI Consistency ကို ထိန်းသိမ်းနိုင်ရန် ဖြစ်ပါသည်။'
        ],
        3  => [
            'ခေါင်းစဉ်' => 'React Router v6 နှင့် Redux Toolkit Global State ချိတ်ဆက်ခြင်း',
            'လုပ်ဆောင်ချက်' => 'Protected Routes, Role-based Navigation နှင့် User Auth State များကို Redux Toolkit ဖြင့် စနစ်တကျ ချိတ်ဆက်တည်ဆောက်ခဲ့သည်။',
            'ကျွမ်းကျင်မှု' => 'React Router DOM, Redux Toolkit slices, global state management, route protection.',
            'အပတ်စဉ်သုံးသပ်ချက်_what' => 'React Router v6 ဖြင့် Page Navigation များနှင့် Redux Toolkit Global State ကို အောင်မြင်စွာ ချိတ်ဆက်ခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_how' => 'Protected Route Wrappers များကို ရေးသား၍ Login မဝင်ထားသော User များအား Login Page သို့ Redirect ပေးနိုင်အောင် ပြုလုပ်ခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_why' => 'Single Page Application ၏ Navigation စွမ်းဆောင်ရည် ချောမွေ့မြန်ဆန်စေရန် ဖြစ်ပါသည်။'
        ],
        4  => [
            'ခေါင်းစဉ်' => 'Authentication Screen UI နှင့် Axios Interceptor ချိတ်ဆက်ခြင်း',
            'လုပ်ဆောင်ချက်' => 'Login, Register, Forgot Password မျက်နှာပြင်များကို အချောသတ်ရေးသားပြီး Backend REST API နှင့် Axios interceptor သုံးကာ ချိတ်ဆက်ခဲ့သည်။',
            'ကျွမ်းကျင်မှု' => 'Axios interceptors, JWT token storage, error toast notifications, form state.',
            'အပတ်စဉ်သုံးသပ်ချက်_what' => 'အသုံးပြုသူ Login, Register မျက်နှာပြင်များနှင့် JWT Token ကို လုံခြုံစွာ ကိုင်တွယ်နိုင်သော Axios Client ကို ရေးသားခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_how' => 'Axios Request/Response Interceptors များကို အသုံးပြု၍ Token Expire ဖြစ်ချိန်တွင် Auto Refresh/Logout ဖြစ်စေရန် ရေးသားခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_why' => 'အသုံးပြုသူများအတွက် စိတ်ချရပြီး ချောမွေ့သော Login အတွေ့အကြုံ ရရှိစေရန် ဖြစ်ပါသည်။'
        ],
        5  => [
            'ခေါင်းစဉ်' => 'Student Dashboard UI နှင့် အပတ်စဉ် တိုးတက်မှု ပြသခြင်း',
            'လုပ်ဆောင်ချက်' => 'ကျောင်းသား Dashboard မျက်နှာပြင်တွင် Progress Circular Indicator, Metrics Cards နှင့် Recent Activity Feed များကို လှပစွာ ရေးဆွဲခဲ့သည်။',
            'ကျွမ်းကျင်မှု' => 'Data visualization, CSS animations, responsive dashboard design, flexbox/grid.',
            'အပတ်စဉ်သုံးသပ်ချက်_what' => 'ကျောင်းသား Dashboard တွင် ၁၂ ပတ်စာ တိုးတက်မှုနှုန်း၊ ရက်အလိုက် မှတ်တမ်းအခြေအနေများနှင့် အရေးကြီး အသိပေးချက်များကို ရေးဆွဲခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_how' => 'Progress Bar Animation များနှင့် Gradient Card Layout များကို အသုံးပြု၍ ခေတ်မီပြီး ရှင်းလင်းသော UI ရရှိအောင် တည်ဆောက်ခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_why' => 'ကျောင်းသားများ မိမိတို့၏ အပတ်စဉ် တိုးတက်မှု အခြေအနေကို တစ်ချက်ကြည့်ရုံဖြင့် ရှင်းလင်းစွာ သိရှိနိုင်စေရန် ဖြစ်ပါသည်။'
        ],
        6  => [
            'ခေါင်းစဉ်' => 'Daily Log Entry Form နှင့် Auto Duration Calculator ဖန်တီးခြင်း',
            'လုပ်ဆောင်ချက်' => 'နေ့စဉ်မှတ်တမ်း ထည့်သွင်းရာတွင် ဝင်ချိန်/ထွက်ချိန် ရွေးချယ်သည်နှင့် အလုပ်ချိန် Duration ကို အလိုအလျောက် တွက်ချက်ပေးသည့် Form ကို ရေးသားခဲ့သည်။',
            'ကျွမ်းကျင်မှု' => 'Dynamic form controls, time difference calculations, client-side validation.',
            'အပတ်စဉ်သုံးသပ်ချက်_what' => 'နေ့စဉ်မှတ်တမ်း ရေးသားရန် Form မျက်နှာပြင်နှင့် အလုပ်ချိန်ကို Live တွက်ချက်ပေးသော JavaScript Logic ကို ရေးသားခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_how' => 'Date/Time Picker များနှင့် Custom Calculation function ကို ရေးသားပြီး Form Validation အပြည့်အစုံ ထည့်သွင်းခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_why' => 'ကျောင်းသားများ အလုပ်ချိန် ထည့်သွင်းရာတွင် အမှားအယွင်း မရှိစေရန်နှင့် အသုံးပြုရ လွယ်ကူစေရန် ဖြစ်ပါသည်။'
        ],
        7  => [
            'ခေါင်းစဉ်' => 'Weekly Reflection Multi-Step Form နှင့် Markdown Editor',
            'လုပ်ဆောင်ချက်' => 'အပတ်စဉ် သုံးသပ်ချက်အတွက် What, How, Why မေးခွန်း (၃) ခုကို အဆင့်လိုက် ဖြေဆိုနိုင်သည့် Stepper Component နှင့် Text Formatting ကို ရေးသားခဲ့သည်။',
            'ကျွမ်းကျင်မှု' => 'Multi-step wizard UI, character counter, auto draft saving, user experience design.',
            'အပတ်စဉ်သုံးသပ်ချက်_what' => 'အပတ်စဉ် သုံးသပ်ချက် (Reflection) များကို အဆင့်လိုက် လွယ်ကူစွာ ရေးသားတင်ပြနိုင်သည့် 3-Step Wizard Component ကို တည်ဆောက်ခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_how' => 'Step Indicator၊ Character Limit Counter များနှင့် Auto-save draft စနစ်ကို LocalStorage ဖြင့် ချိတ်ဆက်ခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_why' => 'ကျောင်းသားများ အပတ်စဉ် သုံးသပ်ချက်များကို စိတ်အေးချမ်းသာစွာ စနစ်တကျ အချက်အလက်ပြည့်စုံစွာ ရေးသားနိုင်စေရန် ဖြစ်ပါသည်။'
        ],
        8  => [
            'ခေါင်းစဉ်' => 'Instructor Review Modal နှင့် Digital Signature Canvas တည်ဆောက်ခြင်း',
            'လုပ်ဆောင်ချက်' => 'ကုမ္ပဏီ Instructor များ အမှတ်ပေးရန်၊ Feedback ရေးသားရန်နှင့် လက်မှတ် ရေးထိုးနိုင်ရန် Canvas-based Digital Signature Pad ကို ရေးသားခဲ့သည်။',
            'ကျွမ်းကျင်မှု' => 'HTML5 Canvas API, digital signature drawing, Base64 export, modal interactions.',
            'အပတ်စဉ်သုံးသပ်ချက်_what' => 'Instructor များအတွက် Report စစ်ဆေးခြင်း၊ Grade ပေးခြင်းနှင့် ဒစ်ဂျစ်တယ် လက်မှတ် ရေးထိုးနိုင်သော Modal UI ကို တည်ဆောက်ခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_how' => 'HTML5 Canvas API ကို အသုံးပြု၍ Touch/Mouse ဖြင့် ချောမွေ့စွာ လက်မှတ် ရေးထိုးနိုင်ပြီး Base64 PNG အဖြစ် Export ထုတ်နိုင်အောင် ပြုလုပ်ခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_why' => 'စာရွက်မလိုဘဲ စနစ်အတွင်း တရားဝင် အကဲဖြတ်ချက်နှင့် လက်မှတ်များ သိမ်းဆည်းနိုင်စေရန် ဖြစ်ပါသည်။'
        ],
        9  => [
            'ခေါင်းစဉ်' => 'Real-time Notification Drawer နှင့် Toast Alert System',
            'လုပ်ဆောင်ချက်' => 'Report အတည်ပြုချက်များ၊ ပြန်ပြင်ရန် အကြောင်းကြားချက်များကို အချိန်နှင့်တပြေးညီ ပြသပေးသည့် Notification Dropdown & Toast Alerts များကို ရေးသားခဲ့သည်။',
            'ကျွမ်းကျင်မှု' => 'Toast notification context, polling mechanism, unread badge counter, UI animations.',
            'အပတ်စဉ်သုံးသပ်ချက်_what' => 'စနစ်အတွင်း အကြောင်းကြားချက်များ ပေါ်ပေါက်လာပါက ချက်ချင်း သတိပေးနိုင်သည့် Notification Drawer နှင့် Toast Alert Provider ကို ရေးသားခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_how' => 'React Context API နှင့် CSS Slide-in Animation များကို အသုံးပြုကာ အလွန်ပေါ့ပါးသော Alert System ကို ဖန်တီးခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_why' => 'အရေးကြီးသော အချက်အလက်များနှင့် Feedback များကို User များ လွတ်သွားခြင်း မရှိစေရန် ဖြစ်ပါသည်။'
        ],
        10 => [
            'ခေါင်းစဉ်' => 'Mobile Responsive Optimization နှင့် Cross-Browser Testing',
            'လုပ်ဆောင်ချက်' => 'ဖုန်း၊ တက်ဘလက်နှင့် ကွန်ပျူတာ အားလုံးတွင် မျက်နှာပြင် အံဝင်ခွင်ကျဖြစ်စေရန် Mobile Navigation Drawer နှင့် Layout Breakpoints များကို ချိန်ညှိခဲ့သည်။',
            'ကျွမ်းကျင်မှု' => 'Responsive web design, mobile-first design, Chrome/Firefox/Safari compatibility testing.',
            'အပတ်စဉ်သုံးသပ်ချက်_what' => 'စနစ်တစ်ခုလုံးကို Mobile, Tablet နှင့် Desktop မျက်နှာပြင် အားလုံးတွင် အပြစ်အနာအဆာမရှိ အံဝင်ခွင်ကျဖြစ်အောင် Responsive Design ချိန်ညှိခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_how' => 'Tailwind CSS responsive modifiers (sm, md, lg, xl) များကို စနစ်တကျ အသုံးပြုပြီး Browser စုံလင်စွာဖြင့် Test ပြုလုပ်ခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_why' => 'မည်သည့် Device ဖြင့်မဆို အဆင်ပြေချောမွေ့စွာ ဝင်ရောက် အသုံးပြုနိုင်စေရန် ဖြစ်ပါသည်။'
        ],
        11 => [
            'ခေါင်းစဉ်' => 'Print Styling (@media print) နှင့် Clean Report Layout ဖန်တီးခြင်း',
            'လုပ်ဆောင်ချက်' => 'အပတ်စဉ် အစီရင်ခံစာများကို Browser Print ပြုလုပ်သည့်အခါ Navigation bars များကို ဖယ်ရှားပြီး တက္ကသိုလ် Standard စာမျက်နှာ ဒီဇိုင်း ရရှိအောင် ပြင်ဆင်ခဲ့သည်။',
            'ကျွမ်းကျင်မှု' => 'CSS print media queries, typography hierarchy, page break controls, paper formatting.',
            'အပတ်စဉ်သုံးသပ်ချက်_what' => 'Browser Print / PDF Save ပြုလုပ်ရာတွင် စာမျက်နှာ အစီအစဉ် ကျနပြီး စာလုံးဖောင့် လှပသော Print Layout Stylesheet ကို ရေးဆွဲခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_how' => '@media print CSS rules များကို အသုံးပြု၍ မလိုလားအပ်သော Navigation Bar များကို ဖျောက်ပြီး Header/Footer Margins များကို တိကျစွာ သတ်မှတ်ခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_why' => 'စာရွက်ပေါ်တွင် ပရော်ဖက်ရှင်နယ်ဆန်ဆန် သန့်ရှင်းသပ်ရပ်စွာ Print ထွက်လာစေရန် ဖြစ်ပါသည်။'
        ],
        12 => [
            'ခေါင်းစဉ်' => 'Performance Optimization, Lazy Loading နှင့် Bundle Size လျှော့ချခြင်း',
            'လုပ်ဆောင်ချက်' => 'Web App ၏ စတင်ပွင့်ချိန်ကို လျှော့ချရန် React.lazy, Suspense, Dynamic Imports နှင့် Image Optimization များကို ဆောင်ရွက်ခဲ့သည်။',
            'ကျွမ်းကျင်မှု' => 'Code splitting, React.lazy, Webpack/Vite bundle analysis, Lighthouse audit.',
            'အပတ်စဉ်သုံးသပ်ချက်_what' => 'Frontend Bundle Size ကို လျှော့ချပြီး Page Load Speed မြန်ဆန်စေရန် Code Splitting နှင့် Lazy Loading များကို ပြုလုပ်ခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_how' => 'Vite Bundle Analyzer ဖြင့် ကြီးမားသော Library များကို စစ်ဆေးခွဲထုတ်ပြီး Lighthouse Performance Score ကို ၉၅+ အထိ မြှင့်တင်နိုင်ခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_why' => 'အင်တာနက်လိုင်း မကောင်းသည့် အခြေအနေများတွင်ပင် အလွန်မြန်ဆန်စွာ စနစ်ပွင့်လာစေရန် ဖြစ်ပါသည်။'
        ],
        13 => [
            'ခေါင်းစဉ်' => 'User Acceptance Testing (UAT) နှင့် အပြီးသတ် ပရောဂျက် တင်ပြခြင်း',
            'လုပ်ဆောင်ချက်' => 'အဖွဲ့သားများနှင့်အတူ End-to-End UI Testing ပြုလုပ်ခြင်း၊ အသေးစား UI bug များကို ပြင်ဆင်ပြီး Internship Final Showcase ကို အောင်မြင်စွာ တင်ပြခဲ့သည်။',
            'ကျွမ်းကျင်မှု' => 'UAT testing, design review, presentation skills, final project delivery.',
            'အပတ်စဉ်သုံးသပ်ချက်_what' => 'Frontend UI/UX တစ်ခုလုံးကို အပြည့်အစုံ စမ်းသပ်စစ်ဆေးပြီး အောင်မြင်စွာ အပြီးသတ် တင်ပြအပ်နှံနိုင်ခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_how' => 'User Testing Session များ ပြုလုပ်၍ လက်တွေ့ကျောင်းသားများနှင့် စမ်းသပ်ကာ အကြံပြုချက်များကို ချက်ချင်း ပြင်ဆင်ဖြည့်စွက်ခဲ့ပါသည်။',
            'အပတ်စဉ်သုံးသပ်ချက်_why' => 'အရည်အသွေး အမြင့်မားဆုံး အသုံးပြုရ အဆင်ပြေဆုံး Frontend Application တစ်ခုအဖြစ် ပေးအပ်နိုင်ခဲ့သောကြောင့် ဖြစ်ပါသည်။'
        ],
    ],
];

// Fallback Myanmar curriculum
$default_curriculum_mm = $weekly_curriculum_mm['Backend PHP/Laravel Developer'];

// Specific revision requested weeks (in Myanmar Language)
$revision_rules_mm = [
    1 => [
        4 => "JWT Authentication Token သက်တမ်းကုန်ဆုံးချိန် ကိုင်တွယ်ပုံနှင့် Frontend Axios interceptor ဖြင့် ချိတ်ဆက်ထားသည့် အဆင့်များကို အသေးစိတ် ထပ်မံဖြည့်စွက်ရေးသားပြီး ပြန်လည်တင်ပြပေးပါရန်။",
    ],
    4 => [
        7 => "ယခုအပတ်အတွင်း ပြုလုပ်ခဲ့သော Database User Permissions နှင့် Security Privilege Matrix သတ်မှတ်ချက်များကို နည်းပညာပိုင်းဆိုင်ရာ အချက်အလက် ပိုမိုပြည့်စုံစွာ ဖြည့်စွက်ပေးပါရန်။",
    ],
    7 => [
        9 => "Cloud Server Monitoring စနစ်တွင် Prometheus Scrapers နှင့် Alert Rules များ ချိန်ညှိထားပုံကို ပိုမိုရှင်းလင်းစွာ Reflection အကျဉ်းချုပ်တွင် ထည့်သွင်းရေးသားပေးပါရန်။",
    ],
    9 => [
        5 => "ယခုအပတ်တွင် ရေးသားခဲ့သော Database Transaction Queries များနှင့် Performance Tuning ပြုလုပ်ခဲ့သည့် ဥပမာများကို သုံးသပ်ချက်တွင် အသေးစိတ် ထည့်သွင်းပေးပါရန်။",
    ],
];

// Leave reasons in Myanmar Language
$absence_rules_mm = [
    0 => [
        '2025-05-21' => ['status' => 'leave', 'reason' => 'ရာသီတုပ်ကွေးဖျားနာမှုကြောင့် ဆေးခွင့်တင်ပြခြင်း (ဆရာဝန်ထောက်ခံစာ ပူးတွဲတင်ပြပြီး)'],
    ],
    1 => [
        '2025-06-11' => ['status' => 'leave', 'reason' => 'တက္ကသိုလ် ပညာရေးဆိုင်ရာ ဆွေးနွေးပွဲနှင့် နှီးနှောဖလှယ်ပွဲ တက်ရောက်ရန် ခွင့်တိုင်ကြားခြင်း'],
    ],
    2 => [
        '2025-07-02' => ['status' => 'leave', 'reason' => 'မိသားစု အရေးပေါ်ကိစ္စကြောင့် ခွင့်တိုင်ကြားခြင်း (ကုမ္ပဏီမန်နေဂျာထံ ကြိုတင်ခွင့်ပြုချက်ရယူပြီး)'],
    ],
    3 => [
        '2025-05-28' => ['status' => 'leave', 'reason' => 'မိသားစုကိစ္စဖြင့် အရေးပေါ်ခွင့်ယူခြင်း (တာဝန်ခံထံ ကြိုတင်အကြောင်းကြားပြီး)'],
    ],
    4 => [
        '2025-06-18' => ['status' => 'leave', 'reason' => 'ဖျားနာမှုကြောင့် ဆေးခွင့်တင်ပြခြင်း (ဆေးလက်မှတ် ပူးတွဲတင်ပြပြီး)'],
    ],
    5 => [
        '2025-07-16' => ['status' => 'leave', 'reason' => 'တက္ကသိုလ် စာမေးပွဲနှင့် ပညာရေးစာရွက်စာတမ်း တင်ပြရန် ခွင့်ရယူခြင်း'],
    ],
    6 => [
        '2025-06-04' => ['status' => 'leave', 'reason' => 'တက္ကသိုလ် သုတေသနပရောဂျက် ဆွေးနွေးတိုင်ပင်ရန် တရားဝင် ခွင့်တိုင်ကြားခြင်း'],
    ],
    7 => [
        '2025-05-14' => ['status' => 'leave', 'reason' => 'ကျန်းမာရေး စစ်ဆေးမှုပြုလုပ်ရန် ဆေးရုံ/ဆေးခန်း ပြသခွင့် တင်ပြခြင်း'],
    ],
    8 => [
        '2025-07-09' => ['status' => 'leave', 'reason' => 'အစာအဆိပ်သင့် ဖျားနာမှုကြောင့် ဆေးခွင့်တင်ပြခြင်း'],
    ],
    9 => [
        '2025-06-25' => ['status' => 'leave', 'reason' => 'တက္ကသိုလ် အလုပ်အကိုင်ပြပွဲ (Career Fair) နှင့် ဆွေးနွေးပွဲ တက်ရောက်ရန် ခွင့်ရယူခြင်း'],
    ],
];

// Loop through each student and create records in Myanmar
$created_students = [];

foreach ($students_data as $s_idx => $s) {
    $comp = $companies[$s['company_idx'] % count($companies)];
    $sup  = $supervisors[$s['supervisor_idx'] % count($supervisors)];

    // Insert user
    $u_stmt = $conn->prepare("INSERT INTO users (username, email, phone, department, position, password, role, is_first_login, academic_year, academic_year_id, status) VALUES (?, ?, ?, ?, ?, ?, 'student', 0, ?, ?, 'Active')");
    $u_stmt->bind_param("sssssssi", $s['username'], $s['email'], $s['phone'], $s['major'], $s['job_role'], $password_hash, $ay_label, $academic_year_id);
    $u_stmt->execute();
    $user_id = $conn->insert_id;

    // Insert student_profiles
    $sp_stmt = $conn->prepare("INSERT INTO student_profiles (user_id, supervisor_id, company_id, full_name, student_roll, major, phone, company_name, job_role, instructor_name, instructor_email, instructor_phone, internship_start_date, internship_end_date) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '2025-05-05', '2025-07-31')");
    $comp_id = (int)$comp['id'];
    $sup_id  = (int)$sup['id'];
    $sp_stmt->bind_param("iiisssssssss", $user_id, $sup_id, $comp_id, $s['full_name'], $s['student_roll'], $s['major'], $s['phone'], $comp['company_name'], $s['job_role'], $s['instructor_name'], $s['instructor_email'], $s['instructor_phone']);
    $sp_stmt->execute();

    echo "Student #".($s_idx+1)." [{$s['student_roll']} - {$s['full_name']}] (UID: $user_id) Created.\n";

    $curriculum = $weekly_curriculum_mm[$s['job_role']] ?? $default_curriculum_mm;

    // Iterate through all 13 weeks
    foreach ($weeks_schedule as $week_no => $w_info) {
        $start_dt = new DateTime($w_info['start']);
        $end_dt   = new DateTime($w_info['end']);
        
        $week_info = $curriculum[$week_no] ?? $default_curriculum_mm[$week_no];
        $week_title = $week_info['ခေါင်းစဉ်'];
        $week_detail= $week_info['လုပ်ဆောင်ချက်'];
        $week_skill = $week_info['ကျွမ်းကျင်မှု'];

        // 1. Daily logs (Monday to Friday) in Myanmar
        $curr = clone $start_dt;
        $day_idx = 1;
        while ($curr <= $end_dt) {
            $day_of_week = (int)$curr->format('N');
            if ($day_of_week <= 5) {
                $date_str = $curr->format('Y-m-d');
                
                // Check leave / absence
                if (isset($absence_rules_mm[$s_idx][$date_str])) {
                    $abs = $absence_rules_mm[$s_idx][$date_str];
                    $att_status = $abs['status'];
                    $reason     = $abs['reason'];
                    $task_title = "ခွင့်ရက် (Leave Day)";
                    $task_detail= "ခွင့်ယူရသည့် အကြောင်းအရင်း - " . $reason;
                    $tasks_perf = "တရားဝင် ခွင့်တိုင်ကြားမှု - " . $reason;
                    $tools_used = "မရှိပါ";
                    $skills_l   = "မရှိပါ";
                    $start_t    = "00:00";
                    $end_t      = "00:00";
                    $calc_dur   = "00:00";
                } else {
                    $att_status = 'present';
                    $reason     = null;
                    $task_title = "ရက် (" . $day_idx . ") - " . $week_title;
                    $task_detail= $week_detail . " (အပတ်စဉ် အပိုင်း " . $day_idx . ")";
                    $tasks_perf = "၁။ နံနက်ခင်း Standup Meeting တက်ရောက်ပြီး ယနေ့လုပ်ဆောင်မည့် Tasks များကို တာဝန်ခံနှင့် ညှိနှိုင်းဆွေးနွေးခြင်း။\n၂။ " . $week_detail . "\n၃။ " . $s['tech_stack'] . " ကို အသုံးပြု၍ Code များ ရေးသားခြင်း၊ စမ်းသပ်ခြင်းနှင့် Code Review ခံယူခြင်း။\n၄။ နေ့စဉ် တာဝန်ပြီးမြောက်မှုများကို ကုမ္ပဏီ Git Repository သို့ Commit / Push တင်ခြင်း။";
                    $tools_used = $s['tech_stack'];
                    $skills_l   = $week_skill . " နှင့် ပြဿနာဖြေရှင်းနိုင်စွမ်း၊ အဖွဲ့လိုက် ပူးပေါင်းဆောင်ရွက်မှု။";
                    $start_t    = "09:00";
                    $end_t      = "17:00";
                    $calc_dur   = "08:00";
                }

                $dl_stmt = $conn->prepare("INSERT INTO daily_logs (internship_id, log_date, attendance_status, reason_for_absence, task_title, task_detail, tasks_performed, tools_used, learnt_skills, start_time, end_time, calculated_duration) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $dl_stmt->bind_param("isssssssssss", $user_id, $date_str, $att_status, $reason, $task_title, $task_detail, $tasks_perf, $tools_used, $skills_l, $start_t, $end_t, $calc_dur);
                $dl_stmt->execute();

                $day_idx++;
            }
            $curr->modify('+1 day');
        }

        // 2. Weekly Reflection in Myanmar
        $what_done = $week_info['အပတ်စဉ်သုံးသပ်ချက်_what'];
        $how_done  = $week_info['အပတ်စဉ်သုံးသပ်ချက်_how'];
        $why_done  = $week_info['အပတ်စဉ်သုံးသပ်ချက်_why'];

        $wr_stmt = $conn->prepare("INSERT INTO weekly_reflections (internship_id, week_number, what_done, how_done, why_done) VALUES (?, ?, ?, ?, ?)");
        $wr_stmt->bind_param("iisss", $user_id, $week_no, $what_done, $how_done, $why_done);
        $wr_stmt->execute();

        // 3. Instructor Evaluation & Signatures in Myanmar
        $is_revision = isset($revision_rules_mm[$s_idx][$week_no]);
        
        if ($is_revision) {
            $rep_status = 'rejected';
            $grade      = 'needs_improvement';
            $inst_comm  = $revision_rules_mm[$s_idx][$week_no];
            $comm       = "Instructor မှ ပြန်လည်ပြင်ဆင်ရန် တောင်းဆိုထားပါသည် - " . $inst_comm;
            $sig_type   = null;
            $sig_val    = null;
        } else {
            $rep_status = 'approved_by_supervisor';
            $grade_pool = ['excellent', 'good', 'excellent', 'good', 'good', 'excellent'];
            $grade      = $grade_pool[($s_idx + $week_no) % count($grade_pool)];
            
            $mm_feedbacks = [
                "ကျောင်းသားသည် ယခုအပတ်အတွင်း သတ်မှတ်ထားသော ပရောဂျက်တာဝန်များကို အချိန်မီ အရည်အသွေးပြည့်မီစွာ ကြိုးစားအားထုတ် ပြီးမြောက်ခဲ့သည်။ နည်းပညာပိုင်းဆိုင်ရာ နားလည်သဘောပေါက်မှု အထူးကောင်းမွန်ပါသည်။",
                "အပတ်စဉ် တာဝန်များကို စိတ်အားထက်သန်စွာ ပြီးမြောက်အောင်မြင်အောင် ဆောင်ရွက်နိုင်ခဲ့ပါသည်။ Code အရည်အသွေးနှင့် အဖွဲ့လိုက် ပူးပေါင်းဆောင်ရွက်မှု ကောင်းမွန်ပါသည်။",
                "တာဝန်ယူမှု၊ တာဝန်ခံမှု အပြည့်ဖြင့် နေ့စဉ်မှတ်တမ်းများနှင့် အစီရင်ခံစာများကို စနစ်တကျ ပြည့်စုံစွာ ရေးသားတင်ပြထားပါသည်။ ဆက်လက်ကြိုးစားရန် အားပေးပါသည်။",
                "လုပ်ငန်းခွင် စည်းမျဉ်းများကို တိကျစွာ လိုက်နာပြီး ပေးအပ်ထားသော နည်းပညာတာဝန်များကို အဆင့်မီ ပြီးမြောက်အောင် စွမ်းဆောင်နိုင်ခဲ့ပါသည်။",
            ];
            $inst_comm  = $mm_feedbacks[($s_idx + $week_no) % count($mm_feedbacks)];
            $comm       = "ကုမ္ပဏီတာဝန်ခံ Instructor ({$s['instructor_name']}) မှ စစ်ဆေးအတည်ပြုပြီး ဖြစ်ပါသည်။";
            $sig_type   = 'typed';
            // Extract single word/name for signature
            $clean_inst = preg_replace('/\s*\(.*?\)/', '', $s['instructor_name']);
            $clean_inst = preg_replace('/^(U|Daw|Dr\.|Dr|Ko|Ma)\s+/i', '', trim($clean_inst));
            $inst_parts = preg_split('/\s+/', trim($clean_inst));
            $sig_val    = $inst_parts[0] ?? $s['instructor_name'];
        }

        $stu_sig_type = 'typed';
        // Extract single word/name for student signature
        $clean_stu = preg_replace('/^(U|Daw|Dr\.|Dr|Ko|Ma)\s+/i', '', trim($s['full_name']));
        $stu_parts = preg_split('/\s+/', trim($clean_stu));
        $stu_sig_val  = $stu_parts[0] ?? $s['full_name'];

        $eval_stmt = $conn->prepare("INSERT INTO report_evaluations (student_id, week_number, grade, comment, instructor_comments, signature_type, signature_value, student_signature_type, student_signature_value, report_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $eval_stmt->bind_param("iissssssss", $user_id, $week_no, $grade, $comm, $inst_comm, $sig_type, $sig_val, $stu_sig_type, $stu_sig_val, $rep_status);
        $eval_stmt->execute();

        // 4. Supervisor Weekly Evaluation (University Supervisor in Myanmar)
        $sup_grades = ['A', 'A', 'B', 'A', 'B', 'A'];
        $sup_grade  = $is_revision ? 'C' : $sup_grades[($s_idx * 2 + $week_no) % count($sup_grades)];
        
        $sup_mm_feedbacks = [
            "အပတ်စဉ် မှတ်တမ်းများနှင့် reflection များကို ပြည့်စုံသပ်ရပ်စွာ ရေးသားတင်ပြထားပါသည်။ တက္ကသိုလ် သတ်မှတ်ချက်များနှင့် ကိုက်ညီမှုရှိပါသည်။",
            "ကျောင်းသား၏ အပတ်စဉ် တိုးတက်မှု အခြေအနေ ကောင်းမွန်ပါသည်။ လက်တွေ့လုပ်ငန်းခွင် အတွေ့အကြုံများကို စနစ်တကျ မှတ်တမ်းတင်ထားနိုင်ပါသည်။",
            "သတ်မှတ်ရက်စွဲများအတိုင်း အချိန်မီ တင်ပြထားပြီး အစီရင်ခံစာ အချက်အလက်များ တိကျပြည့်စုံပါသည်။",
            "ကျေနပ်ဖွယ်ရာ တိုးတက်မှု ရှိပါသည်။ နောက်အပတ်များတွင်လည်း ဤကဲ့သို့ပင် ကြိုးစားအားထုတ်သွားရန် တိုက်တွန်းပါသည်။",
        ];
        
        $sup_comm   = $is_revision 
            ? "Company Instructor ၏ ပြန်လည်ပြင်ဆင်ရန် တောင်းဆိုချက် (Revision Request) ကို ဂရုတစိုက် လိုက်နာ၍ အမြန်ဆုံး ပြင်ဆင်တင်ပြရန် ကြီးကြပ်အကြံပြုပါသည်။"
            : $sup_mm_feedbacks[($s_idx + $week_no) % count($sup_mm_feedbacks)];

        $sup_eval_stmt = $conn->prepare("INSERT INTO supervisor_weekly_evaluations (student_id, week_number, supervisor_id, weekly_grade, supervisor_comments) VALUES (?, ?, ?, ?, ?)");
        $sup_eval_stmt->bind_param("iiiss", $user_id, $week_no, $sup_id, $sup_grade, $sup_comm);
        $sup_eval_stmt->execute();

        // 5. Notifications in Myanmar
        if ($is_revision) {
            $notif_title = "အပတ်စဉ် (" . $week_no . ") အစီရင်ခံစာ ပြန်လည်ပြင်ဆင်ရန် တောင်းဆိုချက်";
            $notif_msg   = "ကုမ္ပဏီတာဝန်ခံ {$s['instructor_name']} မှ အပတ်စဉ် ($week_no) အစီရင်ခံစာအား ပြန်လည်ပြင်ဆင်ရန် တောင်းဆိုထားပါသည်။ Feedback: {$inst_comm}";
            $notif_type  = "instructor_rejected";
            $n_stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, related_week, student_id, is_read) VALUES (?, ?, ?, ?, ?, ?, 0)");
            $n_stmt->bind_param("isssii", $user_id, $notif_title, $notif_msg, $notif_type, $week_no, $user_id);
            $n_stmt->execute();
        } else {
            if ($week_no <= 12) {
                $notif_title = "အပတ်စဉ် (" . $week_no . ") အစီရင်ခံစာ အတည်ပြုပြီးပါပြီ";
                $notif_msg   = "သင်၏ အပတ်စဉ် ($week_no) အစီရင်ခံစာကို ကုမ္ပဏီတာဝန်ခံ {$s['instructor_name']} မှ အတည်ပြုပြီး တက္ကသိုလ်ကြီးကြပ်ဆရာ {$sup['username']} မှ စစ်ဆေးပြီးဖြစ်ပါသည်။";
                $notif_type  = "supervisor_approved";
                $n_stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, related_week, student_id, is_read) VALUES (?, ?, ?, ?, ?, ?, 1)");
                $n_stmt->bind_param("isssii", $user_id, $notif_title, $notif_msg, $notif_type, $week_no, $user_id);
                $n_stmt->execute();
            }
        }
    }

    $created_students[] = [
        'roll'     => $s['student_roll'],
        'name'     => $s['full_name'],
        'username' => $s['username'],
        'company'  => $comp['company_name'],
        'supervisor'=> $sup['username'],
        'role'     => $s['job_role'],
    ];
}

echo "\n====================================================\n";
echo "SUCCESS! 10 Students generated with full Myanmar content!\n";
echo "====================================================\n";
