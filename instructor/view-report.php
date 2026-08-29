<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/ui_helpers.php';

$db = $mysqli ?? $conn;

/**
 * Render standard error modal
 */
function render_error($title, $msg, $icon = '🔒')
{
?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($title) ?> – InternReport</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="bg-slate-50 flex items-center justify-center min-h-screen p-6 font-sans">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-xl p-8 max-w-md w-full text-center">
            <div class="w-16 h-16 rounded-full bg-red-50 text-red-500 border border-red-100 flex items-center justify-center text-3xl mx-auto mb-4"><?= $icon ?></div>
            <h2 class="text-lg font-black text-slate-800 mb-2"><?= htmlspecialchars($title) ?></h2>
            <p class="text-xs text-slate-500 leading-relaxed mb-6"><?= htmlspecialchars($msg) ?></p>
            <div class="pt-4 border-t border-slate-100 flex flex-col gap-2">
                <a href="../login.php" class="inline-flex items-center justify-center px-4 py-2.5 bg-slate-800 hover:bg-slate-900 text-white rounded-xl text-xs font-bold transition shadow-xs">
                    Return to Login
                </a>
            </div>
        </div>
    </body>
    </html>
<?php
    exit;
}

/**
 * Render Student Blocked Screen to prevent students from self-evaluating
 */
function render_student_blocked_error($student_username = 'Student')
{
    http_response_code(403);
?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Access Denied – Student Session Active</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="bg-slate-900 min-h-screen flex items-center justify-center p-6 font-sans antialiased text-slate-100">
        <div class="max-w-md w-full bg-slate-800/90 backdrop-blur border border-slate-700/80 rounded-2xl p-8 shadow-2xl text-center relative overflow-hidden">
            <div class="absolute top-0 left-0 right-0 h-1.5 bg-gradient-to-r from-red-500 via-amber-500 to-red-500"></div>

            <div class="w-16 h-16 rounded-2xl bg-red-500/10 border border-red-500/20 text-red-400 flex items-center justify-center text-3xl mx-auto mb-5 shadow-inner">
                🛡️
            </div>

            <span class="inline-block px-3 py-1 bg-red-500/20 text-red-300 text-[11px] font-bold tracking-wider uppercase rounded-full mb-3 border border-red-500/30">
                Security Guard Active
            </span>

            <h2 class="text-xl font-black text-white mb-2 tracking-tight">Access Denied: Student Account Detected</h2>
            
            <p class="text-xs text-slate-300 leading-relaxed mb-4">
                You are currently signed in as student <strong class="text-white font-bold bg-slate-700 px-2 py-0.5 rounded">@<?= htmlspecialchars($student_username) ?></strong>.
            </p>

            <div class="bg-slate-900/80 border border-slate-700 rounded-xl p-4 mb-6 text-left">
                <p class="text-[11px] text-amber-300 font-semibold mb-1 flex items-center gap-1.5">
                    <span>⚠️</span> သတိပေးချက် / Notice:
                </p>
                <p class="text-[11px] text-slate-300 leading-relaxed">
                    ကျောင်းသားအကောင့် Login ဝင်ထားသော Browser ဖြင့် Instructor အကဲဖြတ်ခြင်း စာမျက်နှာကို ဝင်ရောက်ခွင့်မပြုပါ။ ဤစာမျက်နှာကို Company Instructor ကသာ Email မှတစ်ဆင့် သီးသန့် ဖွင့်လှစ် အကဲဖြတ်ရမည် ဖြစ်ပါသည်။
                </p>
            </div>

            <div class="flex flex-col gap-2.5">
                <a href="../student/student-dashboard.php" class="w-full inline-flex items-center justify-center px-4 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl text-xs font-bold transition shadow-md shadow-indigo-600/20">
                    📋 Return to Student Dashboard
                </a>
                <a href="../logout.php" class="w-full inline-flex items-center justify-center px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-200 rounded-xl text-xs font-semibold transition border border-slate-600">
                    🚪 Log Out from Student Account
                </a>
            </div>

            <p class="text-[11px] text-slate-400 mt-5 leading-relaxed">
                💡 <em>Testing note: If testing as instructor, open this link in an Incognito / Private Window.</em>
            </p>
        </div>
    </body>
    </html>
<?php
    exit;
}

// ── 1. Security Check: Prevent Logged-in Students from Accessing Instructor Page ──
if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'student') {
    render_student_blocked_error($_SESSION['username'] ?? 'Student');
}

// ── 2. Magic Link Token Validation (No Login Required) ─────────────
$token = trim($_GET['token'] ?? '');

if (!$token || !preg_match('/^[a-f0-9]{32,64}$/i', $token)) {
    http_response_code(404);
    render_error('Invalid Link', 'No valid magic link token provided in the URL.', '✕');
}

$stmt = $db->prepare("SELECT internship_id AS student_id, week_number, expires_at FROM magic_links WHERE token = ? AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1");
$stmt->bind_param("s", $token);
$stmt->execute();
$res = $stmt->get_result();
$link = $res ? $res->fetch_assoc() : null;

if (!$link) {
    http_response_code(404);
    render_error('Link Expired or Already Reviewed', 'This magic link is no longer active. The report may have already been evaluated and approved, or the link has expired.', '🔒');
}

$student_id  = (int) $link['student_id'];
$week_number = (int) $link['week_number'];

// ── Fetch Student Profile ────────────────────────────────────────
$profile_stmt = $db->prepare("SELECT sp.*, u.username, u.email, sup.id AS supervisor_user_id, sup.username AS supervisor_name, sup.email AS supervisor_email FROM student_profiles sp JOIN users u ON u.id = sp.user_id LEFT JOIN users sup ON sup.id = sp.supervisor_id WHERE sp.user_id = ?");
$profile_stmt->bind_param("i", $student_id);
$profile_stmt->execute();
$res = $profile_stmt->get_result();
$profile = $res ? $res->fetch_assoc() : [];

$student_name = ($profile['full_name'] ?? '') ?: ($profile['username'] ?? 'Student');
$student_roll = $profile['student_roll'] ?? '';
$company_name = $profile['company_name'] ?? '';
$intern_start = $profile['internship_start_date'] ?? null;

// ── Calculate Week Date Range ────────────────────────────────────
$week_start = '';
$week_end   = '';
if ($intern_start) {
    $start = new DateTime($intern_start);
    $day_of_week = (int) $start->format('N'); // Monday = 1, Saturday = 6
    $days_to_saturday = $day_of_week === 6 ? 0 : (6 - $day_of_week + 7) % 7;
    $end_of_week_one = (clone $start)->modify("+{$days_to_saturday} days");

    if ($week_number === 1) {
        $week_start = $start->format('Y-m-d');
        $week_end = $end_of_week_one->format('Y-m-d');
    } else {
        $week_start_date = (clone $end_of_week_one)->modify('+1 day');
        if ($week_number > 2) {
            $week_start_date->modify('+' . (($week_number - 2) * 7) . ' days');
        }
        $week_start = $week_start_date->format('Y-m-d');
        $week_end = (clone $week_start_date)->modify('+6 days')->format('Y-m-d');
    }
} else {
    $first_log = $db->prepare("SELECT MIN(log_date) FROM daily_logs WHERE internship_id = ?");
    $first_log->bind_param("i", $student_id);
    $first_log->execute();
    $res = $first_log->get_result();
    $row = $res ? $res->fetch_row() : null;
    $first_date_str = $row[0] ?? null;
    if ($first_date_str) {
        $base = new DateTime($first_date_str);
        $base->modify('+' . (($week_number - 1) * 7) . ' days');
        $week_start = $base->format('Y-m-d');
        $week_end   = (clone $base)->modify('+6 days')->format('Y-m-d');
    }
}

// ── Fetch Daily Logs for This Week ───────────────────────────────
$daily_stmt = $db->prepare("SELECT * FROM daily_logs WHERE internship_id = ? AND log_date BETWEEN ? AND ? ORDER BY log_date ASC");
$daily_stmt->bind_param("iss", $student_id, $week_start, $week_end);
$daily_stmt->execute();
$res = $daily_stmt->get_result();
$daily_logs = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

// ── Fetch Weekly Reflection ──────────────────────────────────────
$ref_stmt = $db->prepare("SELECT * FROM weekly_reflections WHERE internship_id = ? AND week_number = ?");
$ref_stmt->bind_param("ii", $student_id, $week_number);
$ref_stmt->execute();
$res = $ref_stmt->get_result();
$reflection = $res ? $res->fetch_assoc() : null;

// ── Fetch Existing Evaluation ────────────────────────────────────
$eval_stmt = $db->prepare("SELECT * FROM report_evaluations WHERE student_id = ? AND week_number = ?");
$eval_stmt->bind_param("ii", $student_id, $week_number);
$eval_stmt->execute();
$res = $eval_stmt->get_result();
$evaluation = $res ? $res->fetch_assoc() : null;

// ── Handle Rejection ─────────────────────────────────────────────
$eval_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_report'])) {
    if ($evaluation && in_array($evaluation['report_status'], ['approved_by_instructor', 'approved_by_supervisor'])) {
        $eval_msg = 'already_approved';
    } else {
        $reject_reason = trim($_POST['reject_reason'] ?? '');
        if (empty($reject_reason)) {
            $eval_msg = 'reject_empty';
        } else {
            $rej = $db->prepare("INSERT INTO report_evaluations (student_id, week_number, grade, comment, instructor_comments, report_status)
                VALUES (?, ?, 'needs_improvement', '', ?, 'rejected')
                ON DUPLICATE KEY UPDATE
                instructor_comments = VALUES(instructor_comments),
                report_status = 'rejected',
                signature_type = NULL, signature_value = NULL,
                evaluated_at = NOW()");
            $rej->bind_param("iis", $student_id, $week_number, $reject_reason);
            $rej->execute();

            // Invalidate/delete magic link upon rejection
            $del_link = $db->prepare("DELETE FROM magic_links WHERE internship_id = ? AND week_number = ?");
            $del_link->bind_param("ii", $student_id, $week_number);
            $del_link->execute();

            // Insert notification for the student
            $instructor_label = ($profile['instructor_name'] ?? '') ?: 'Your instructor';
            $notif_stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, type, related_week) VALUES (?, ?, ?, 'instructor_rejected', ?)");
            $notif_title = 'Report Rejected by Instructor';
            $notif_msg = $instructor_label . ' has rejected your Week ' . $week_number . ' report. Reason: ' . $reject_reason;
            $notif_stmt->bind_param("issi", $student_id, $notif_title, $notif_msg, $week_number);
            $notif_stmt->execute();

            $eval_stmt->bind_param("ii", $student_id, $week_number);
            $eval_stmt->execute();
            $res = $eval_stmt->get_result();
            $evaluation = $res ? $res->fetch_assoc() : null;
            $eval_msg = 'rejected';
        }
    }
}

// ── Handle Feedback Submission ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_feedback'])) {
    if ($evaluation && in_array($evaluation['report_status'], ['approved_by_instructor', 'approved_by_supervisor'])) {
        $eval_msg = 'already_approved';
    } else {
        $grade          = $_POST['grade'] ?? '';
        $comment        = trim($_POST['comment'] ?? '');
        $signature_type = $_POST['signature_type'] ?? '';
        $typed_name     = trim($_POST['typed_name'] ?? '');

        $allowed = ['excellent', 'good', 'average', 'needs_improvement'];
        $sig_ok  = false;
        $sig_val = null;

        // Validate signature
        if ($signature_type === 'typed' && !empty($typed_name)) {
            $sig_ok  = true;
            $sig_val = $typed_name;
        } elseif ($signature_type === 'uploaded' && isset($_FILES['signature_file']) && $_FILES['signature_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['signature_file'];
            $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if (in_array($ext, ['jpg', 'jpeg', 'png'], true) && $file['size'] <= 2 * 1024 * 1024) {
                $safe_name = 'sig_' . $student_id . '_w' . $week_number . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                $dest = __DIR__ . '/../uploads/signatures/' . $safe_name;

                if (move_uploaded_file($file['tmp_name'], $dest)) {
                    $sig_ok  = true;
                    $sig_val = $safe_name;
                }
            }
        }

        // Determine which field failed for debugging
        $missing_fields = [];
        if (!in_array($grade, $allowed, true)) $missing_fields[] = 'Grade';
        if (empty($comment)) $missing_fields[] = 'Comment';
        if (!$sig_ok) $missing_fields[] = 'Signature';

        if (!empty($missing_fields)) {
            $eval_msg = 'error_missing:' . implode(', ', $missing_fields);
        } else {
            $upsert = $db->prepare("INSERT INTO report_evaluations (student_id, week_number, grade, comment, signature_type, signature_value, report_status)
                VALUES (?, ?, ?, ?, ?, ?, 'approved_by_instructor')
                ON DUPLICATE KEY UPDATE
                grade = VALUES(grade), comment = VALUES(comment),
                signature_type = VALUES(signature_type), signature_value = VALUES(signature_value),
                report_status = 'approved_by_instructor', evaluated_at = NOW()");
            $upsert->bind_param("iissss", $student_id, $week_number, $grade, $comment, $signature_type, $sig_val);
            $upsert->execute();

            // Expire / delete magic link immediately so the link is dead
            $del_link = $db->prepare("DELETE FROM magic_links WHERE internship_id = ? AND week_number = ?");
            $del_link->bind_param("ii", $student_id, $week_number);
            $del_link->execute();

            // Insert notification for the student
            $instructor_label = ($profile['instructor_name'] ?? '') ?: 'Your instructor';
            $notif_stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, type, related_week) VALUES (?, ?, ?, 'instructor_approved', ?)");
            $notif_title = 'Report Approved by Instructor';
            $notif_msg = $instructor_label . ' has signed and approved your Week ' . $week_number . ' report with grade "' . ucfirst(str_replace('_', ' ', $grade)) . '".';
            $notif_stmt->bind_param("issi", $student_id, $notif_title, $notif_msg, $week_number);
            $notif_stmt->execute();

            // Notify assigned supervisor
            if (!empty($profile['supervisor_user_id'])) {
                require_once __DIR__ . '/../config/notify.php';
                notify_user_once(
                    $db,
                    (int) $profile['supervisor_user_id'],
                    'Report Ready for Supervisor Review',
                    $student_name . "'s Week " . $week_number . ' report has been reviewed and approved by the company instructor and is ready for your evaluation.',
                    'report_needs_review',
                    $week_number,
                    $student_id
                );
            }

            $eval_stmt->bind_param("ii", $student_id, $week_number);
            $eval_stmt->execute();
            $res = $eval_stmt->get_result();
            $evaluation = $res ? $res->fetch_assoc() : null;
            $eval_msg = 'saved';
        }
    }
}

// ── Grade Labels ─────────────────────────────────────────────────
$grade_labels = [
    'excellent'         => ['Excellent',          'text-emerald-600', 'bg-emerald-50'],
    'good'              => ['Good',               'text-blue-600',    'bg-blue-50'],
    'average'           => ['Average',            'text-amber-600',   'bg-amber-50'],
    'needs_improvement' => ['Needs Improvement',  'text-red-600',     'bg-red-50'],
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Internship Report – Week <?= $week_number ?> – InternReport</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Alex+Brush&family=Dancing+Script:wght@400;700&family=Great+Vibes&display=swap" rel="stylesheet">
    <style>
        .sig-preview {
            font-family: 'Great Vibes', cursive;
            font-size: 28px;
            color: #1e293b;
            min-height: 48px;
            line-height: 1.4;
        }

        .sig-type-great {
            font-family: 'Great Vibes', cursive;
        }

        .sig-type-dancing {
            font-family: 'Dancing Script', cursive;
        }

        .sig-type-alex {
            font-family: 'Alex Brush', cursive;
        }

        .student-sig-preview {
            font-family: 'Great Vibes', cursive;
            font-size: 24px;
            color: #1e293b;
            min-height: 36px;
            line-height: 1.4;
        }

        @media print {
            @page {
                size: A4 portrait;
                margin: 12mm 15mm;
            }

            body {
                background: #ffffff !important;
                color: #0f172a !important;
            }

            .print\:hidden,
            button,
            form {
                display: none !important;
            }

            .print-card-avoid {
                break-inside: avoid !important;
            }

            .bg-white {
                background: #ffffff !important;
            }

            .border {
                border-color: #cbd5e1 !important;
            }

            .shadow-sm {
                box-shadow: none !important;
            }
        }
    </style>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'inter': ['Inter', 'sans-serif']
                    }
                }
            }
        }
    </script>
    <script>
        function previewTypedSig() {
            var name = document.getElementById('typed_name').value;
            var el = document.getElementById('sig_preview');
            if (el) el.textContent = name || '—';
        }

        function switchSigType(type) {
            var typed = document.getElementById('sig-typed-fields');
            var upload = document.getElementById('sig-upload-fields');
            var fontSel = document.getElementById('sig_font_select');
            if (type === 'typed') {
                typed.classList.remove('hidden');
                upload.classList.add('hidden');
                if (fontSel) fontSel.classList.remove('hidden');
            } else {
                typed.classList.add('hidden');
                upload.classList.remove('hidden');
                if (fontSel) fontSel.classList.add('hidden');
            }
        }

        function previewSigFont(font) {
            var el = document.getElementById('sig_preview');
            if (!el) return;
            el.className = 'sig-preview';
            if (font === 'dancing') el.classList.add('sig-type-dancing');
            else if (font === 'alex') el.classList.add('sig-type-alex');
            else el.classList.add('sig-type-great');
        }
    </script>
</head>

<body class="bg-slate-50 font-sans antialiased p-6">

    <div class="max-w-6xl mx-auto space-y-6">

        <div class="flex items-center justify-between print:hidden">
            <div class="flex items-center gap-2">
                <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">🏢 Company Instructor Review</span>
            </div>
            <button type="button" onclick="window.print()" class="px-4 py-2 bg-slate-800 hover:bg-black text-white text-xs font-bold rounded-xl transition cursor-pointer shadow-xs">🖨️ Print Report</button>
        </div>

        <?php if ($eval_msg === 'saved'): ?>
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-semibold px-4 py-3 rounded-xl print:hidden">
                ✅ Feedback submitted and report approved.
            </div>
        <?php endif; ?>

        <?php if ($eval_msg === 'rejected'): ?>
        <?php endif; ?>
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
            <div class="flex items-start justify-between flex-wrap gap-4">
                <div class="flex items-center gap-4">
                    <div class="w-14 h-14 rounded-full bg-teal-100 text-teal-700 flex items-center justify-center text-lg font-bold shrink-0">
                        <?= strtoupper(($student_name)[0]) ?>
                    </div>
                    <div>
                        <h1 class="text-sm font-black text-slate-800"><?= htmlspecialchars($student_name) ?></h1>
                        <?php if ($student_roll): ?>
                            <p class="text-sm text-slate-400 font-mono mt-0.5">Roll: <?= htmlspecialchars($student_roll) ?></p>
                        <?php endif; ?>
                        <?php if ($company_name): ?>
                            <p class="text-sm text-slate-400 mt-0.5"><?= htmlspecialchars($company_name) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="text-right">
                    <div class="inline-flex items-center gap-1.5 text-sm font-bold text-indigo-600 bg-indigo-50 border border-indigo-100 px-3 py-1.5 rounded-full">
                        📅 Week <?= $week_number ?>
                        <?php if ($week_start): ?>
                            <span class="text-indigo-400 mx-0.5">|</span>
                            <?= (new DateTime($week_start))->format('d-M-Y') ?> – <?= (new DateTime($week_end))->format('d-M-Y') ?>
                        <?php endif; ?>
                    </div>
                    <div class="mt-1.5">
                        <?php if ($evaluation && in_array($evaluation['report_status'], ['approved_by_instructor', 'approved_by_supervisor'])): ?>
                            <span class="text-sm bg-emerald-100 text-emerald-700 px-2.5 py-0.5 rounded-full font-bold">✅ Approved by Instructor</span>
                        <?php else: ?>
                            <span class="text-sm bg-amber-100 text-amber-700 px-2.5 py-0.5 rounded-full font-bold">⏳ Pending Review</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ════ 2-COLUMN GRID ════ -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- ─── LEFT (2/3): Logs + Reflection ─── -->
            <div class="lg:col-span-2 space-y-6">

                <!-- Daily Logs Table -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
                        <h2 class="text-xs font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                            <span class="p-1 bg-blue-50 text-blue-600 rounded">📝</span> Daily Log History (နေ့စဉ်မှတ်တမ်း)
                        </h2>
                        <span class="text-sm text-slate-400"><?= count($daily_logs) ?> day(s)</span>
                    </div>
                    <?php if (!empty($daily_logs)): ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs">
                                <thead>
                                    <tr class="bg-slate-50 text-slate-600 font-bold uppercase tracking-wider text-xs border-b border-slate-200">
                                        <th class="px-3.5 py-3 text-left whitespace-nowrap">ရက်စွဲ / နေ့</th>
                                        <th class="px-3.5 py-3 text-left whitespace-nowrap">တက်ရောက်မှုအခြေအနေ</th>
                                        <th class="px-3.5 py-3 text-left min-w-[160px]">ဆောင်ရွက်မည့်လုပ်ငန်း</th>
                                        <th class="px-3.5 py-3 text-left min-w-[200px]">အမှန်တကယ် လုပ်ဆောင်ဖြစ်သော လုပ်ငန်းစဉ်များ</th>
                                        <th class="px-3.5 py-3 text-left min-w-[120px]">အသုံးပြုသောပစ္စည်းများ</th>
                                        <th class="px-3.5 py-3 text-left min-w-[160px]">လေ့လာသိရှိသော အသိပညာ</th>
                                        <th class="px-3.5 py-3 text-left whitespace-nowrap">ကြာချိန်</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 text-slate-700">
                                    <?php foreach ($daily_logs as $log): ?>
                                        <tr class="hover:bg-slate-50/80 transition align-top">
                                            <td class="px-3.5 py-3 font-semibold text-slate-800 whitespace-nowrap">
                                                <?= (new DateTime($log['log_date']))->format('D, d M Y') ?>
                                            </td>
                                            <td class="px-3.5 py-3 whitespace-nowrap">
                                                <?php if (($log['attendance_status'] ?? 'present') === 'present'): ?>
                                                    <span class="inline-flex items-center gap-1 text-xs font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded-md">✅ Present</span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center gap-1 text-xs font-bold text-red-700 bg-red-50 border border-red-200 px-2 py-0.5 rounded-md">❌ Absent</span>
                                                    <?php if (!empty($log['reason_for_absence'])): ?>
                                                        <span class="text-xs text-slate-500 block mt-1 leading-normal"><?= htmlspecialchars($log['reason_for_absence']) ?></span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                            <?php $is_absent = ($log['attendance_status'] ?? 'present') === 'absent'; ?>
                                            <td class="px-3.5 py-3 font-medium text-slate-800 break-words whitespace-normal leading-relaxed">
                                                <?= $is_absent ? '—' : htmlspecialchars($log['task_title'] ?? '—') ?>
                                            </td>
                                            <td class="px-3.5 py-3 text-slate-600 break-words whitespace-normal leading-relaxed">
                                                <?= $is_absent ? '—' : nl2br(htmlspecialchars($log['tasks_performed'] ?? '—')) ?>
                                            </td>
                                            <td class="px-3.5 py-3 text-emerald-700 font-mono text-xs break-words whitespace-normal leading-relaxed">
                                                <?= $is_absent ? '—' : htmlspecialchars($log['tools_used'] ?? '—') ?>
                                            </td>
                                            <td class="px-3.5 py-3 text-slate-600 break-words whitespace-normal leading-relaxed">
                                                <?= $is_absent ? '—' : htmlspecialchars($log['learnt_skills'] ?? '—') ?>
                                            </td>
                                            <td class="px-3.5 py-3 font-mono text-blue-700 font-bold whitespace-nowrap">
                                                <?= htmlspecialchars($log['calculated_duration'] ?? '08:00') ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="p-8 text-center text-xs text-slate-400">No daily logs found for Week <?= $week_number ?>.</div>
                    <?php endif; ?>
                </div>

                <!-- Weekly Reflection -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-100">
                        <h2 class="text-xs font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                            <span class="p-1 bg-emerald-50 text-emerald-600 rounded">📊</span> Weekly Reflection (အပတ်စဉ် သုံးသပ်ချက်)
                        </h2>
                    </div>
                    <?php if ($reflection): ?>
                        <div class="p-5 space-y-4">
                            <div>
                                <span class="text-xs font-bold text-slate-600 block mb-1">What was done? <span class="text-slate-400 font-normal">/ ဘာလုပ်သလဲ</span></span>
                                <p class="text-xs text-slate-800 leading-relaxed bg-slate-50 border border-slate-100 rounded-xl p-3.5 whitespace-normal break-words"><?= nl2br(htmlspecialchars($reflection['what_done'] ?? '')) ?></p>
                            </div>
                            <div>
                                <span class="text-xs font-bold text-slate-600 block mb-1">How was it done? <span class="text-slate-400 font-normal">/ ဘယ်လိုလုပ်ပါသလဲ</span></span>
                                <p class="text-xs text-slate-800 leading-relaxed bg-slate-50 border border-slate-100 rounded-xl p-3.5 whitespace-normal break-words"><?= nl2br(htmlspecialchars($reflection['how_done'] ?? '')) ?></p>
                            </div>
                            <div>
                                <span class="text-xs font-bold text-slate-600 block mb-1">Why was it done? <span class="text-slate-400 font-normal">/ ဘာကြောင့်လုပ်ပါသလဲ</span></span>
                                <p class="text-xs text-slate-800 leading-relaxed bg-slate-50 border border-slate-100 rounded-xl p-3.5 whitespace-normal break-words"><?= nl2br(htmlspecialchars($reflection['why_done'] ?? '')) ?></p>
                            </div>

                            <!-- Realistic Small Student Signature below Weekly Reflection -->
                            <?php if (!empty($evaluation['student_signature_type']) && !empty($evaluation['student_signature_value'])): ?>
                                <div class="pt-4 border-t border-slate-100 flex items-center justify-between">
                                    <div>
                                        <span class="text-xs font-semibold text-slate-400 block">ကျောင်းသား လက်မှတ် (Student Signature)</span>
                                        <span class="text-xs font-bold text-slate-700"><?= htmlspecialchars($student_name) ?> (<?= htmlspecialchars($student_roll) ?>)</span>
                                    </div>
                                    <div class="px-3 py-1 bg-slate-50 border border-slate-200/80 rounded-lg select-none">
                                        <?php if ($evaluation['student_signature_type'] === 'typed'): ?>
                                            <span style="font-family:'Great Vibes',cursive; font-size:22px; color:#1e293b; pointer-events:none;">
                                                <?= htmlspecialchars($evaluation['student_signature_value']) ?>
                                            </span>
                                        <?php elseif ($evaluation['student_signature_type'] === 'uploaded'): ?>
                                            <img src="../uploads/signatures/<?= htmlspecialchars($evaluation['student_signature_value']) ?>" alt="Student Signature" class="max-h-8 object-contain" style="pointer-events:none;">
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="p-8 text-center text-xs text-slate-400">No weekly reflection submitted for Week <?= $week_number ?>.</div>
                    <?php endif; ?>
                </div>

            </div>

            <!-- ─── RIGHT (1/3): Evaluation Panel ─── -->
            <div class="lg:col-span-1">
                <div id="evaluation-panel" class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden sticky top-6 scroll-mt-24">
                    <div class="px-5 py-3 border-b border-slate-100">
                        <h2 class="text-xs font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                            <span class="p-1 bg-purple-50 text-purple-600 rounded">⭐</span> Instructor Evaluation
                        </h2>
                    </div>

                    <?php if ($evaluation && in_array($evaluation['report_status'], ['approved_by_instructor', 'approved_by_supervisor'])): ?>
                        <!-- ── Already Approved: Show Result ── -->
                        <div class="p-5 space-y-4">
                            <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-sm font-bold text-slate-400 uppercase tracking-wider">Grade</span>
                                    <?php $g = $grade_labels[$evaluation['grade']] ?? ['Unknown', 'text-slate-600', 'bg-slate-100']; ?>
                                    <span class="text-sm font-bold <?= $g[1] ?> <?= $g[2] ?> px-2.5 py-0.5 rounded-full"><?= $g[0] ?></span>
                                </div>
                                <div>
                                    <span class="text-sm font-bold text-slate-400 uppercase tracking-wider block mb-1">Comment</span>
                                    <p class="text-xs text-slate-600 leading-relaxed"><?= nl2br(htmlspecialchars($evaluation['comment'])) ?></p>
                                </div>
                            </div>

                            <!-- Display Signatures -->
                            <div class="bg-slate-50 rounded-xl p-4 border border-slate-100">
                                <span class="text-sm font-bold text-slate-400 uppercase tracking-wider block mb-2">Instructor Sign-off</span>
                                <!-- Instructor Signature -->
                                <div>
                                    <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-1">Instructor Signature</span>
                                    <?php if ($evaluation['signature_type'] === 'typed'): ?>
                                        <p class="sig-preview sig-type-great" style="font-family:'Great Vibes',cursive; font-size:28px; color:#1e293b;">
                                            <?= htmlspecialchars($evaluation['signature_value']) ?>
                                        </p>
                                    <?php elseif ($evaluation['signature_type'] === 'uploaded'): ?>
                                        <img src="../uploads/signatures/<?= htmlspecialchars($evaluation['signature_value']) ?>" alt="Instructor Signature" class="max-h-16 object-contain">
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="flex items-center gap-2 text-sm text-emerald-600 bg-emerald-50 px-3 py-2 rounded-xl font-bold">
                                <span>✅</span> Report Approved by Instructor
                            </div>

                            <p class="text-sm text-slate-400 text-center">
                                Evaluated on <?= !empty($evaluation['evaluated_at']) ? (new DateTime($evaluation['evaluated_at']))->format('d M Y, h:i A') : date('d M Y') ?>
                            </p>
                        </div>

                    <?php elseif ($evaluation && $evaluation['report_status'] === 'rejected'): ?>
                        <!-- ── Rejected: Show Reason ── -->
                        <div class="p-5 space-y-4">
                            <div class="bg-red-50 rounded-xl p-4 border border-red-200">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="text-red-500 text-sm">❌</span>
                                    <span class="text-sm font-bold text-red-700">Report Rejected</span>
                                </div>
                                <div>
                                    <span class="text-sm font-bold text-red-400 uppercase tracking-wider block mb-1">Reason for Rejection</span>
                                    <p class="text-xs text-red-600 leading-relaxed"><?= nl2br(htmlspecialchars($evaluation['instructor_comments'] ?? '')) ?></p>
                                </div>
                            </div>

                            <p class="text-sm text-slate-500 text-center leading-relaxed">
                                Please revise your daily logs and reflection for <strong>Week <?= $week_number ?></strong>, then ask the student to regenerate a new magic link for re-review.
                            </p>

                            <p class="text-sm text-slate-300 text-center">
                                Rejected on <?= (new DateTime($evaluation['evaluated_at']))->format('d M Y, h:i A') ?>
                            </p>
                        </div>

                    <?php else: ?>
                        <!-- ── Fresh / Interactive Decision Form ── -->
                        <form method="POST" enctype="multipart/form-data" class="p-5 space-y-4" id="instructorEvaluationForm">
                            
                            <!-- Decision Mode Selector Tabs -->
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Select Decision</label>
                                <div class="grid grid-cols-2 gap-2 p-1 bg-slate-100/90 rounded-2xl border border-slate-200">
                                    <button type="button" onclick="setDecisionMode('approve')" id="tab-decision-approve"
                                        class="flex items-center justify-center gap-1.5 py-2.5 px-3 rounded-xl font-bold text-xs transition-all duration-200 cursor-pointer bg-white text-emerald-700 shadow-sm border border-emerald-200">
                                        <span>✅</span>
                                        <span>Approve</span>
                                    </button>
                                    <button type="button" onclick="setDecisionMode('reject')" id="tab-decision-reject"
                                        class="flex items-center justify-center gap-1.5 py-2.5 px-3 rounded-xl font-bold text-xs transition-all duration-200 cursor-pointer text-slate-600 hover:text-rose-600 hover:bg-white/70">
                                        <span>❌</span>
                                        <span>Reject</span>
                                    </button>
                                </div>
                            </div>

                            <!-- ════════ APPROVE CONTAINER ════════ -->
                            <div id="decision-approve-container" class="space-y-4">
                                <p class="text-xs text-slate-500 leading-relaxed bg-emerald-50/60 border border-emerald-100 rounded-xl p-3">
                                    Rate <strong class="text-slate-800">Week <?= $week_number ?></strong>, provide feedback, and sign to approve this report.
                                </p>

                                <!-- Grade -->
                                <div>
                                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Performance Grade / အဆင့်သတ်မှတ်ချက်</label>
                                    <div class="space-y-2">
                                        <label class="flex items-center gap-2.5 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl cursor-pointer hover:bg-emerald-50 hover:border-emerald-200 transition">
                                            <input type="radio" name="grade" value="excellent" <?= ($evaluation['grade'] ?? '') === 'excellent' ? 'checked' : '' ?> class="accent-emerald-600">
                                            <span class="text-xs font-semibold text-slate-700">Excellent</span>
                                            <span class="text-xs text-slate-400 ml-auto">Outstanding</span>
                                        </label>
                                        <label class="flex items-center gap-2.5 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl cursor-pointer hover:bg-blue-50 hover:border-blue-200 transition">
                                            <input type="radio" name="grade" value="good" <?= ($evaluation['grade'] ?? 'good') === 'good' ? 'checked' : '' ?> class="accent-blue-600">
                                            <span class="text-xs font-semibold text-slate-700">Good</span>
                                            <span class="text-xs text-slate-400 ml-auto">Meets expectations</span>
                                        </label>
                                        <label class="flex items-center gap-2.5 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl cursor-pointer hover:bg-amber-50 hover:border-amber-200 transition">
                                            <input type="radio" name="grade" value="average" <?= ($evaluation['grade'] ?? '') === 'average' ? 'checked' : '' ?> class="accent-amber-600">
                                            <span class="text-xs font-semibold text-slate-700">Average</span>
                                            <span class="text-xs text-slate-400 ml-auto">Room to grow</span>
                                        </label>
                                        <label class="flex items-center gap-2.5 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl cursor-pointer hover:bg-rose-50 hover:border-rose-200 transition">
                                            <input type="radio" name="grade" value="needs_improvement" <?= ($evaluation['grade'] ?? '') === 'needs_improvement' ? 'checked' : '' ?> class="accent-rose-600">
                                            <span class="text-xs font-semibold text-slate-700">Needs Improvement</span>
                                            <span class="text-xs text-slate-400 ml-auto">Requires attention</span>
                                        </label>
                                    </div>
                                </div>

                                <!-- Comment -->
                                <div>
                                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">Review Comment / အကဲဖြတ်မှတ်ချက်</label>
                                    <textarea name="comment" id="review_comment" rows="4" placeholder="Write your detailed feedback and guidance for the student…"
                                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-700 focus:outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10 transition resize-none"><?= htmlspecialchars($evaluation['comment'] ?? '') ?></textarea>
                                </div>

                                <!-- Signature Section -->
                                <div class="border-t border-slate-100 pt-4">
                                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">✍️ Instructor Signature / လက်မှတ်</label>

                                    <!-- Signature Type Toggle -->
                                    <div class="flex gap-2 mb-3">
                                        <button type="button" onclick="switchSigType('typed')" id="btn-typed"
                                            class="flex-1 px-2 py-1.5 text-xs font-bold rounded-lg border transition cursor-pointer bg-indigo-600 text-white border-indigo-600">
                                            ✏️ Type Name
                                        </button>
                                        <button type="button" onclick="switchSigType('uploaded')" id="btn-uploaded"
                                            class="flex-1 px-2 py-1.5 text-xs font-bold rounded-lg border transition cursor-pointer bg-white text-slate-600 border-slate-200 hover:bg-slate-50">
                                            📷 Upload Image
                                        </button>
                                    </div>

                                    <!-- Option 1: Typed Signature -->
                                    <div id="sig-typed-fields" class="space-y-3">
                                        <input type="hidden" name="signature_type" value="typed" id="sig_type_input">

                                        <div>
                                            <label class="block text-xs font-bold text-slate-400 mb-1">Type your full name</label>
                                            <input type="text" name="typed_name" id="typed_name" placeholder="e.g. U Aung Kyaw"
                                                oninput="previewTypedSig()"
                                                class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-700 focus:outline-none focus:border-indigo-500 transition">
                                        </div>

                                        <!-- Live Preview -->
                                        <div class="bg-white border-2 border-dashed border-slate-200 rounded-xl p-3 text-center">
                                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Signature Preview</p>
                                            <p id="sig_preview" class="sig-preview sig-type-great" style="font-family:'Great Vibes',cursive; font-size:28px; color:#1e293b;">—</p>
                                        </div>
                                    </div>

                                    <!-- Option 2: Upload Signature -->
                                    <div id="sig-upload-fields" class="hidden space-y-2">
                                        <div class="bg-slate-50 border border-dashed border-slate-300 rounded-xl p-4 text-center">
                                            <div class="text-2xl mb-1">📷</div>
                                            <p class="text-xs text-slate-500 font-semibold mb-2">Upload handwritten signature</p>
                                            <label class="inline-block px-3 py-1.5 bg-white border border-slate-200 rounded-lg text-xs font-bold text-slate-600 hover:bg-slate-50 cursor-pointer transition shadow-2xs">
                                                Choose JPG/PNG
                                                <input type="file" name="signature_file" accept=".jpg,.jpeg,.png" class="hidden">
                                            </label>
                                            <p class="text-[10px] text-slate-400 mt-1.5">Max 2MB • JPG or PNG only</p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Submit Approve Button -->
                                <div class="pt-2">
                                    <button type="submit" name="submit_feedback" id="approve-submit-btn"
                                        onclick="prepareApproveSubmit()"
                                        class="w-full px-4 py-3 bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 text-white font-bold text-xs rounded-xl shadow-md hover:shadow-emerald-500/20 active:scale-[0.98] transition-all duration-200 cursor-pointer flex items-center justify-center gap-2">
                                        <span>✅</span>
                                        <span>Confirm & Sign Approval</span>
                                    </button>
                                </div>
                            </div>

                            <!-- ════════ REJECT CONTAINER ════════ -->
                            <div id="decision-reject-container" class="hidden space-y-4">
                                <div class="bg-rose-50/80 rounded-2xl p-4 border border-rose-200 space-y-3">
                                    <div class="flex items-center gap-2">
                                        <span class="text-rose-500 text-base">⚠️</span>
                                        <div>
                                            <h3 class="text-xs font-bold text-rose-800 uppercase tracking-wider">Reason for Rejection</h3>
                                            <p class="text-[11px] text-rose-600">ပြန်လည်ပြင်ဆင်ရန် အကြောင်းပြချက်</p>
                                        </div>
                                    </div>
                                    <textarea name="reject_reason" id="reject_reason" rows="5" placeholder="Explain specifically why this report needs revision (e.g. Missing daily logs, incomplete tasks, revise reflection)…"
                                        class="w-full bg-white border border-rose-300 rounded-xl px-3 py-2 text-xs text-slate-800 placeholder:text-rose-300 focus:outline-none focus:ring-2 focus:ring-rose-400/30 focus:border-rose-500 transition resize-none"></textarea>
                                    <p class="text-[11px] text-rose-500 font-medium">This message will be sent directly to the student so they can revise and re-submit.</p>
                                </div>

                                <!-- Submit Reject Button -->
                                <div class="pt-2">
                                    <button type="submit" name="reject_report" id="reject-submit-btn"
                                        onclick="prepareRejectSubmit()"
                                        class="w-full px-4 py-3 bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs rounded-xl shadow-md hover:shadow-rose-500/20 active:scale-[0.98] transition-all duration-200 cursor-pointer flex items-center justify-center gap-2">
                                        <span>❌</span>
                                        <span>Confirm Rejection & Request Revision</span>
                                    </button>
                                </div>
                            </div>
                        </form>
                    <?php endif; ?>

                    <!-- Info Footer -->
                    <div class="px-5 py-3 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                        <p class="text-xs text-slate-400 text-center leading-relaxed">
                            Signing sets status to <strong>Approved by Instructor</strong> and forwards to University Supervisor.
                        </p>
                    </div>
                </div>
            </div>

        </div>

        <div class="text-center text-xs text-slate-400 py-3">Powered by InternReport</div>
    </div>

    <script>
        // Decision Mode Switcher (Approve vs Reject)
        function setDecisionMode(mode) {
            var approveCont = document.getElementById('decision-approve-container');
            var rejectCont  = document.getElementById('decision-reject-container');
            var tabApprove  = document.getElementById('tab-decision-approve');
            var tabReject   = document.getElementById('tab-decision-reject');
            var reviewComment = document.getElementById('review_comment');
            var rejectReason  = document.getElementById('reject_reason');

            if (mode === 'approve') {
                if (approveCont) approveCont.classList.remove('hidden');
                if (rejectCont) rejectCont.classList.add('hidden');
                if (tabApprove) tabApprove.className = 'flex items-center justify-center gap-1.5 py-2.5 px-3 rounded-xl font-bold text-xs transition-all duration-200 cursor-pointer bg-white text-emerald-700 shadow-sm border border-emerald-200';
                if (tabReject) tabReject.className  = 'flex items-center justify-center gap-1.5 py-2.5 px-3 rounded-xl font-bold text-xs transition-all duration-200 cursor-pointer text-slate-600 hover:text-rose-600 hover:bg-white/70';
                if (rejectReason) {
                    rejectReason.removeAttribute('required');
                }
                if (reviewComment) {
                    reviewComment.setAttribute('required', 'required');
                }
            } else {
                if (approveCont) approveCont.classList.add('hidden');
                if (rejectCont) rejectCont.classList.remove('hidden');
                if (tabReject) tabReject.className  = 'flex items-center justify-center gap-1.5 py-2.5 px-3 rounded-xl font-bold text-xs transition-all duration-200 cursor-pointer bg-white text-rose-700 shadow-sm border border-rose-200';
                if (tabApprove) tabApprove.className = 'flex items-center justify-center gap-1.5 py-2.5 px-3 rounded-xl font-bold text-xs transition-all duration-200 cursor-pointer text-slate-600 hover:text-emerald-600 hover:bg-white/70';
                if (reviewComment) {
                    reviewComment.removeAttribute('required');
                }
                if (rejectReason) {
                    rejectReason.setAttribute('required', 'required');
                    rejectReason.focus();
                }
            }
        }

        function prepareApproveSubmit() {
            var rejectReason = document.getElementById('reject_reason');
            var reviewComment = document.getElementById('review_comment');
            if (rejectReason) rejectReason.removeAttribute('required');
            if (reviewComment) reviewComment.setAttribute('required', 'required');
        }

        function prepareRejectSubmit() {
            var rejectReason = document.getElementById('reject_reason');
            var reviewComment = document.getElementById('review_comment');
            if (reviewComment) reviewComment.removeAttribute('required');
            if (rejectReason) rejectReason.setAttribute('required', 'required');
        }

        // Toggle signature type buttons styling
        function switchSigType(type) {
            var typed = document.getElementById('sig-typed-fields');
            var upload = document.getElementById('sig-upload-fields');
            var hidden = document.getElementById('sig_type_input');
            var btnT = document.getElementById('btn-typed');
            var btnU = document.getElementById('btn-uploaded');

            if (type === 'typed') {
                if (typed) typed.classList.remove('hidden');
                if (upload) upload.classList.add('hidden');
                if (hidden) hidden.value = 'typed';
                if (btnT) btnT.className = 'flex-1 px-2 py-1.5 text-xs font-bold rounded-lg border transition cursor-pointer bg-indigo-600 text-white border-indigo-600';
                if (btnU) btnU.className = 'flex-1 px-2 py-1.5 text-xs font-bold rounded-lg border transition cursor-pointer bg-white text-slate-600 border-slate-200 hover:bg-slate-50';
            } else {
                if (typed) typed.classList.add('hidden');
                if (upload) upload.classList.remove('hidden');
                if (hidden) hidden.value = 'uploaded';
                if (btnU) btnU.className = 'flex-1 px-2 py-1.5 text-xs font-bold rounded-lg border transition cursor-pointer bg-indigo-600 text-white border-indigo-600';
                if (btnT) btnT.className = 'flex-1 px-2 py-1.5 text-xs font-bold rounded-lg border transition cursor-pointer bg-white text-slate-600 border-slate-200 hover:bg-slate-50';
            }
        }
    </script>

</body>

</html>