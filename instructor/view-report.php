<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/ui_helpers.php';

$logged_in_instructor = isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'instructor';
$db = $mysqli ?? $conn;

// ── Token Validation ─────────────────────────────────────────────
$token = trim($_GET['token'] ?? '');

if (!$token || !preg_match('/^[a-f0-9]{32,64}$/i', $token)) {
    http_response_code(404);
    render_error('Invalid Link', 'No valid token provided in the URL.', '✕');
}

$stmt = $db->prepare("SELECT student_id, week_number FROM weekly_reports WHERE instructor_review_code = ? LIMIT 1");
$stmt->bind_param("s", $token);
$stmt->execute();
$res = $stmt->get_result();
$link = $res ? $res->fetch_assoc() : null;

if (!$link) {
    http_response_code(404);
    render_error('Link Expired or Invalid', 'This magic link is no longer valid. Please ask the student to generate a new one.', '🔒');
}

$student_id  = (int) $link['student_id'];
$week_number = (int) $link['week_number'];

// ── Fetch Student Profile ────────────────────────────────────────
$profile_stmt = $db->prepare("
    SELECT sp.*, u.username, u.email, u.phone,
           COALESCE(c.company_name, '') AS company_name,
           sup.id AS supervisor_user_id, sup.username AS supervisor_name, sup.email AS supervisor_email
    FROM student_profiles sp
    JOIN users u ON u.id = sp.user_id
    LEFT JOIN companies c ON c.id = sp.company_id
    LEFT JOIN users sup ON sup.id = sp.supervisor_id
    WHERE sp.user_id = ?
");
$profile_stmt->bind_param("i", $student_id);
$profile_stmt->execute();
$res = $profile_stmt->get_result();
$profile = $res ? $res->fetch_assoc() : [];

$student_name = ($profile['username'] ?? 'Student');
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
    $first_log = $db->prepare("SELECT MIN(log_date) FROM daily_logs WHERE student_id = ?");
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
$daily_stmt = $db->prepare("SELECT * FROM daily_logs WHERE student_id = ? AND log_date BETWEEN ? AND ? ORDER BY log_date ASC");
$daily_stmt->bind_param("iss", $student_id, $week_start, $week_end);
$daily_stmt->execute();
$res = $daily_stmt->get_result();
$daily_logs = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

// ── Fetch Weekly Reflection & Report ─────────────────────────────
$rep_stmt = $db->prepare("SELECT * FROM weekly_reports WHERE student_id = ? AND week_number = ? LIMIT 1");
$rep_stmt->bind_param("ii", $student_id, $week_number);
$rep_stmt->execute();
$res = $rep_stmt->get_result();
$weekly_report = $res ? $res->fetch_assoc() : null;

$reflection = $weekly_report ? [
    'what_done' => $weekly_report['what_done'],
    'how_done'  => $weekly_report['how_done'],
    'why_done'  => $weekly_report['why_done'],
] : null;

$evaluation = ($weekly_report && !empty($weekly_report['instructor_grade'])) ? [
    'grade'               => $weekly_report['instructor_grade'],
    'comment'             => $weekly_report['instructor_comments'],
    'instructor_comments' => $weekly_report['instructor_comments'],
    'report_status'       => $weekly_report['status'],
    'evaluated_at'        => $weekly_report['submitted_at'],
] : null;

// ── Handle Rejection ─────────────────────────────────────────────
$eval_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_report'])) {
    $reject_reason = trim($_POST['reject_reason'] ?? '');

    if (empty($reject_reason)) {
        $eval_msg = 'reject_empty';
    } else {
        $rej = $db->prepare("UPDATE weekly_reports SET
            instructor_grade = 'needs_improvement',
            instructor_comments = ?,
            status = 'rejected'
            WHERE student_id = ? AND week_number = ?");
        $rej->bind_param("sii", $reject_reason, $student_id, $week_number);
        $rej->execute();

        // Insert notification for the student
        require_once __DIR__ . '/../config/notify.php';
        $instructor_label = ($profile['instructor_name'] ?? '') ?: 'Your instructor';
        $notif_title = 'Report Rejected by Instructor';
        $notif_msg = $instructor_label . ' has rejected your Week ' . $week_number . ' report. Reason: ' . $reject_reason;
        $student_link = '../student/student-dashboard.php?week=' . (int)$week_number;
        createNotification($db, $student_id, $notif_title, $notif_msg, $student_link, 'instructor_rejected', $week_number, $student_id);

        $rep_stmt->bind_param("ii", $student_id, $week_number);
        $rep_stmt->execute();
        $res = $rep_stmt->get_result();
        $weekly_report = $res ? $res->fetch_assoc() : null;
        $evaluation = ($weekly_report && !empty($weekly_report['instructor_grade'])) ? [
            'grade'               => $weekly_report['instructor_grade'],
            'comment'             => $weekly_report['instructor_comments'],
            'instructor_comments' => $weekly_report['instructor_comments'],
            'report_status'       => $weekly_report['status'],
            'evaluated_at'        => $weekly_report['submitted_at'],
        ] : null;
        $eval_msg = 'rejected';
    }
}

// ── Handle Feedback Submission ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_feedback'])) {
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
    if (!$sig_ok) $missing_fields[] = 'Signature (type=' . htmlspecialchars($signature_type) . ', name=' . htmlspecialchars($typed_name) . ')';

    if (!empty($missing_fields)) {
        $eval_msg = 'error_missing:' . implode(', ', $missing_fields);
    } else {
        $upsert = $db->prepare("UPDATE weekly_reports SET
            instructor_grade = ?,
            instructor_comments = ?,
            status = 'approved_by_instructor'
            WHERE student_id = ? AND week_number = ?");
        $upsert->bind_param("ssii", $grade, $comment, $student_id, $week_number);
        $upsert->execute();

        // Insert notification for the student
        require_once __DIR__ . '/../config/notify.php';
        $instructor_label = ($profile['instructor_name'] ?? '') ?: 'Your instructor';
        $notif_title = 'Report Approved by Instructor';
        $notif_msg = $instructor_label . ' has signed and approved your Week ' . $week_number . ' report with grade "' . ucfirst(str_replace('_', ' ', $grade)) . '".';
        $student_link = '../student/student-dashboard.php?week=' . (int)$week_number;
        createNotification($db, $student_id, $notif_title, $notif_msg, $student_link, 'instructor_approved', $week_number, $student_id);

        // Notify assigned supervisor
        if (!empty($profile['supervisor_user_id'])) {
            $sup_link = '../supervisor/view-student-dashboard.php?id=' . (int)$student_id . '&week=' . (int)$week_number;
            notify_user_once(
                $db,
                (int) $profile['supervisor_user_id'],
                'Report Ready for Supervisor Review',
                $student_name . "'s Week " . $week_number . ' report has been reviewed and approved by the company instructor and is ready for your evaluation.',
                'report_needs_review',
                $week_number,
                $student_id,
                null,
                false,
                $sup_link
            );
        }

        $rep_stmt->bind_param("ii", $student_id, $week_number);
        $rep_stmt->execute();
        $res = $rep_stmt->get_result();
        $weekly_report = $res ? $res->fetch_assoc() : null;
        $evaluation = ($weekly_report && !empty($weekly_report['instructor_grade'])) ? [
            'grade'               => $weekly_report['instructor_grade'],
            'comment'             => $weekly_report['instructor_comments'],
            'instructor_comments' => $weekly_report['instructor_comments'],
            'report_status'       => $weekly_report['status'],
            'evaluated_at'        => $weekly_report['submitted_at'],
        ] : null;
        $eval_msg = 'saved';
    }
}

// ── Grade Labels ─────────────────────────────────────────────────
$grade_labels = [
    'excellent'         => ['Excellent',          'text-emerald-600', 'bg-emerald-50'],
    'good'              => ['Good',               'text-blue-600',    'bg-blue-50'],
    'average'           => ['Average',            'text-amber-600',   'bg-amber-50'],
    'needs_improvement' => ['Needs Improvement',  'text-red-600',     'bg-red-50'],
];

function render_error($title, $msg, $icon) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($title) ?></title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="bg-slate-50 flex items-center justify-center min-h-screen p-6">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-8 max-w-sm text-center">
            <div class="w-14 h-14 rounded-full bg-red-100 text-red-500 flex items-center justify-center text-2xl mx-auto mb-4"><?= $icon ?></div>
            <h2 class="text-sm font-black text-slate-800 mb-2"><?= htmlspecialchars($title) ?></h2>
            <p class="text-xs text-slate-400"><?= htmlspecialchars($msg) ?></p>
        </div>
    </body>
    </html>
    <?php
    exit;
}
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
        .sig-preview { font-family: 'Great Vibes', cursive; font-size: 28px; color: #1e293b; min-height: 48px; line-height: 1.4; }
        .sig-type-great  { font-family: 'Great Vibes', cursive; }
        .sig-type-dancing { font-family: 'Dancing Script', cursive; }
        .sig-type-alex   { font-family: 'Alex Brush', cursive; }
        .student-sig-preview { font-family: 'Great Vibes', cursive; font-size: 24px; color: #1e293b; min-height: 36px; line-height: 1.4; }

        @media print {
            @page { size: A4 portrait; margin: 12mm 15mm; }
            body { background: #ffffff !important; color: #0f172a !important; }
            .print\:hidden, button, form { display: none !important; }
            .print-card-avoid { break-inside: avoid !important; }
            .bg-white { background: #ffffff !important; }
            .border { border-color: #cbd5e1 !important; }
            .shadow-sm { box-shadow: none !important; }
        }
    </style>
    <script>
        tailwind.config = {
            theme: { extend: { fontFamily: { 'inter': ['Inter', 'sans-serif'] } } }
        }
    </script>
    <script>
    function previewTypedSig() {
        var name = document.getElementById('typed_name').value;
        var el   = document.getElementById('sig_preview');
        if (el) el.textContent = name || '—';
    }
    function switchSigType(type) {
        var typed  = document.getElementById('sig-typed-fields');
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
        if (font === 'dancing')  el.classList.add('sig-type-dancing');
        else if (font === 'alex') el.classList.add('sig-type-alex');
        else el.classList.add('sig-type-great');
    }
    </script>
</head>
<body class="bg-slate-50 font-sans antialiased p-6">

<div class="max-w-6xl mx-auto space-y-6">

    <div class="flex items-center justify-between print:hidden">
        <?php if ($logged_in_instructor): ?>
        <a href="instructor-dashboard.php" class="inline-flex items-center gap-2 text-xs font-bold text-teal-600 hover:text-teal-800 transition">
            <span class="w-7 h-7 rounded-lg bg-teal-50 flex items-center justify-center text-sm">←</span> Back to Dashboard
        </a>
        <?php endif; ?>
        <button type="button" onclick="window.print()" class="px-4 py-2 bg-slate-800 text-white text-xs font-bold rounded-xl">Print Report</button>
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
                    <?php if ($evaluation && $evaluation['report_status'] === 'approved_by_instructor'): ?>
                        <span class="text-sm bg-emerald-100 text-emerald-700 px-2.5 py-0.5 rounded-full font-bold">✅ Approved by Instructor</span>
                    <?php else: ?>
                        <span class="text-sm bg-amber-100 text-amber-700 px-2.5 py-0.5 rounded-full font-bold">⏳ Pending Review</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Student Signature Display -->
    <?php if (!empty($evaluation['student_signature_type']) && !empty($evaluation['student_signature_value'])): ?>
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
        <div class="flex items-center gap-4">
            <div class="flex-1">
                <span class="text-sm font-bold text-slate-400 uppercase tracking-wider block mb-1">Student Signature</span>
                <?php if ($evaluation['student_signature_type'] === 'typed'): ?>
                    <p class="student-sig-preview" style="font-family:'Great Vibes',cursive; font-size:24px; color:#1e293b;">
                        <?= htmlspecialchars($evaluation['student_signature_value']) ?>
                    </p>
                <?php elseif ($evaluation['student_signature_type'] === 'uploaded'): ?>
                    <img src="../uploads/signatures/<?= htmlspecialchars($evaluation['student_signature_value']) ?>" alt="Student Signature" class="max-h-14 object-contain">
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ════ 2-COLUMN GRID ════ -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- ─── LEFT (2/3): Logs + Reflection ─── -->
        <div class="lg:col-span-2 space-y-6">

            <!-- Daily Logs Table -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
                    <h2 class="text-xs font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                        <span class="p-1 bg-blue-50 text-blue-600 rounded">📝</span> Daily Logs
                    </h2>
                    <span class="text-sm text-slate-400"><?= count($daily_logs) ?> day(s)</span>
                </div>
                <?php if (!empty($daily_logs)): ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="bg-slate-50 text-slate-500 font-bold uppercase tracking-wider text-sm">
                                <th class="px-3 py-2.5 text-left">Date</th>
                                <th class="px-3 py-2.5 text-left">Status</th>
                                <th class="px-3 py-2.5 text-left">Intended Task</th>
                                <th class="px-3 py-2.5 text-left">Details</th>
                                <th class="px-3 py-2.5 text-left">Actual Task</th>
                                <th class="px-3 py-2.5 text-left">Tools</th>
                                <th class="px-3 py-2.5 text-left">Duration</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($daily_logs as $log): ?>
                            <tr class="hover:bg-slate-50 transition">
                                <td class="px-3 py-2.5 font-medium text-slate-700 whitespace-nowrap">
                                    <?= (new DateTime($log['log_date']))->format('D, d M') ?>
                                </td>
                                <td class="px-3 py-2.5 whitespace-nowrap">
                                    <?php if (($log['attendance_status'] ?? 'present') === 'present'): ?>
                                        <span class="inline-flex items-center gap-1 text-sm font-bold text-emerald-600 bg-emerald-50 px-1.5 py-0.5 rounded">✅ Present</span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 text-sm font-bold text-red-600 bg-red-50 px-1.5 py-0.5 rounded">❌ Absent</span>
                                        <?php if (!empty($log['reason_for_absence'])): ?>
                                            <span class="text-sm text-slate-400 block mt-0.5" title="<?= htmlspecialchars($log['reason_for_absence']) ?>">Reason noted</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <?php $is_absent = ($log['attendance_status'] ?? 'present') === 'absent'; ?>
                                <td class="px-3 py-2.5 text-slate-600 max-w-[140px] truncate" title="<?= $is_absent ? '' : htmlspecialchars($log['task_title'] ?? '') ?>"><?= $is_absent ? '-' : htmlspecialchars($log['task_title'] ?? '') ?></td>
                                <td class="px-3 py-2.5 text-slate-600 max-w-[160px] truncate" title="<?= $is_absent ? '' : htmlspecialchars($log['task_detail'] ?? '') ?>"><?= $is_absent ? '-' : htmlspecialchars($log['task_detail'] ?? '') ?></td>
                                <td class="px-3 py-2.5 text-slate-600 max-w-[160px] truncate" title="<?= $is_absent ? '' : htmlspecialchars($log['tasks_performed'] ?? '') ?>"><?= $is_absent ? '-' : htmlspecialchars($log['tasks_performed'] ?? '') ?></td>
                                <td class="px-3 py-2.5 text-slate-600"><?= $is_absent ? '-' : htmlspecialchars($log['tools_used'] ?? '—') ?></td>
                                <td class="px-3 py-2.5 font-mono text-blue-600 font-bold whitespace-nowrap"><?= htmlspecialchars($log['calculated_duration']) ?></td>
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
                        <span class="p-1 bg-emerald-50 text-emerald-600 rounded">📊</span> Weekly Reflection
                    </h2>
                </div>
                <?php if ($reflection): ?>
                <div class="p-5 space-y-4">
                    <div>
                        <span class="text-sm font-bold text-slate-400 uppercase tracking-wider block mb-1">What was done?</span>
                        <p class="text-xs text-slate-600 leading-relaxed bg-slate-50 rounded-xl p-3"><?= nl2br(htmlspecialchars($reflection['what_done'] ?? '')) ?></p>
                    </div>
                    <div>
                        <span class="text-sm font-bold text-slate-400 uppercase tracking-wider block mb-1">How was it done?</span>
                        <p class="text-xs text-slate-600 leading-relaxed bg-slate-50 rounded-xl p-3"><?= nl2br(htmlspecialchars($reflection['how_done'] ?? '')) ?></p>
                    </div>
                    <div>
                        <span class="text-sm font-bold text-slate-400 uppercase tracking-wider block mb-1">Why was it done?</span>
                        <p class="text-xs text-slate-600 leading-relaxed bg-slate-50 rounded-xl p-3"><?= nl2br(htmlspecialchars($reflection['why_done'] ?? '')) ?></p>
                    </div>
                </div>
                <?php else: ?>
                <div class="p-8 text-center text-xs text-slate-400">No weekly reflection submitted for Week <?= $week_number ?>.</div>
                <?php endif; ?>
            </div>

        </div>

        <!-- ─── RIGHT (1/3): Evaluation Panel ─── -->
        <div class="lg:col-span-1">
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden sticky top-6">
                <div class="px-5 py-3 border-b border-slate-100">
                    <h2 class="text-xs font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                        <span class="p-1 bg-purple-50 text-purple-600 rounded">⭐</span> Instructor Evaluation
                    </h2>
                </div>

                <?php if ($evaluation && $evaluation['report_status'] === 'approved_by_instructor'): ?>
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
                        <span class="text-sm font-bold text-slate-400 uppercase tracking-wider block mb-2">Signatures</span>
                        <!-- Student Signature -->
                        <?php if (!empty($evaluation['student_signature_type']) && !empty($evaluation['student_signature_value'])): ?>
                        <div class="mb-3 pb-3 border-b border-slate-200">
                            <span class="text-sm font-bold text-slate-400 uppercase tracking-wider block mb-1">Student</span>
                            <?php if ($evaluation['student_signature_type'] === 'typed'): ?>
                                <p class="student-sig-preview" style="font-family:'Great Vibes',cursive; font-size:24px; color:#1e293b;">
                                    <?= htmlspecialchars($evaluation['student_signature_value']) ?>
                                </p>
                            <?php elseif ($evaluation['student_signature_type'] === 'uploaded'): ?>
                                <img src="../uploads/signatures/<?= htmlspecialchars($evaluation['student_signature_value']) ?>" alt="Student Signature" class="max-h-14 object-contain">
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <!-- Instructor Signature -->
                        <div>
                            <span class="text-sm font-bold text-slate-400 uppercase tracking-wider block mb-1">Instructor</span>
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

                    <p class="text-sm text-slate-300 text-center">
                        Evaluated on <?= (new DateTime($evaluation['evaluated_at']))->format('d M Y, h:i A') ?>
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
                <!-- ── Fresh / Update Form ── -->
                <form method="POST" enctype="multipart/form-data" class="p-5 space-y-4">
                    <input type="hidden" name="submit_feedback" value="1">
                    <p class="text-sm text-slate-400 leading-relaxed">
                        Rate <strong class="text-slate-600">Week <?= $week_number ?></strong>, provide feedback, and sign to approve.
                    </p>

                    <!-- Grade -->
                    <div>
                        <label class="block text-sm font-bold text-slate-500 mb-2">Performance Grade</label>
                        <div class="space-y-2">
                            <label class="flex items-center gap-2.5 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl cursor-pointer hover:bg-emerald-50 hover:border-emerald-200 transition">
                                <input type="radio" name="grade" value="excellent" <?= ($evaluation['grade'] ?? '') === 'excellent' ? 'checked' : '' ?> class="accent-emerald-600">
                                <span class="text-xs font-semibold text-slate-700">Excellent</span>
                                <span class="text-sm text-slate-400 ml-auto">Outstanding</span>
                            </label>
                            <label class="flex items-center gap-2.5 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl cursor-pointer hover:bg-blue-50 hover:border-blue-200 transition">
                                <input type="radio" name="grade" value="good" <?= ($evaluation['grade'] ?? 'good') === 'good' ? 'checked' : '' ?> class="accent-blue-600">
                                <span class="text-xs font-semibold text-slate-700">Good</span>
                                <span class="text-sm text-slate-400 ml-auto">Meets expectations</span>
                            </label>
                            <label class="flex items-center gap-2.5 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl cursor-pointer hover:bg-amber-50 hover:border-amber-200 transition">
                                <input type="radio" name="grade" value="average" <?= ($evaluation['grade'] ?? '') === 'average' ? 'checked' : '' ?> class="accent-amber-600">
                                <span class="text-xs font-semibold text-slate-700">Average</span>
                                <span class="text-sm text-slate-400 ml-auto">Room to grow</span>
                            </label>
                            <label class="flex items-center gap-2.5 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl cursor-pointer hover:bg-red-50 hover:border-red-200 transition">
                                <input type="radio" name="grade" value="needs_improvement" <?= ($evaluation['grade'] ?? '') === 'needs_improvement' ? 'checked' : '' ?> class="accent-red-600">
                                <span class="text-xs font-semibold text-slate-700">Needs Improvement</span>
                                <span class="text-sm text-slate-400 ml-auto">Requires attention</span>
                            </label>
                        </div>
                    </div>

                    <!-- Comment -->
                    <div>
                        <label class="block text-sm font-bold text-slate-500 mb-1">Review Comment</label>
                        <textarea name="comment" rows="4" required placeholder="Write your detailed feedback…"
                            class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-700 focus:outline-none focus:border-blue-500 transition resize-none"><?= htmlspecialchars($evaluation['comment'] ?? '') ?></textarea>
                    </div>

                    <!-- ═══ SIGNATURE SECTION ═══ -->
                    <div class="border-t border-slate-100 pt-4">
                        <label class="block text-sm font-bold text-slate-500 mb-2">✍️ Instructor Signature</label>

                        <!-- Signature Type Toggle -->
                        <div class="flex gap-2 mb-3">
                            <button type="button" onclick="switchSigType('typed')" id="btn-typed"
                                class="flex-1 px-2 py-1.5 text-sm font-bold rounded-lg border transition cursor-pointer
                                bg-indigo-600 text-white border-indigo-600">
                                ✏️ Type Name
                            </button>
                            <button type="button" onclick="switchSigType('uploaded')" id="btn-uploaded"
                                class="flex-1 px-2 py-1.5 text-sm font-bold rounded-lg border transition cursor-pointer
                                bg-white text-slate-600 border-slate-200 hover:bg-slate-50">
                                📷 Upload Image
                            </button>
                        </div>

                        <!-- ── Option 1: Typed Signature ── -->
                        <div id="sig-typed-fields" class="space-y-3">
                            <input type="hidden" name="signature_type" value="typed" id="sig_type_input">

                            <div>
                                <label class="block text-sm font-bold text-slate-400 mb-0.5">Type your full name</label>
                                <input type="text" name="typed_name" id="typed_name" placeholder="e.g. U Aung Kyaw"
                                    oninput="previewTypedSig()"
                                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-700 focus:outline-none focus:border-blue-500 transition">
                            </div>

                            <!-- Font Style Selector -->
                            <div id="sig_font_select">
                                <label class="block text-sm font-bold text-slate-400 mb-1">Font Style</label>
                                <div class="flex gap-1.5">
                                    <button type="button" onclick="previewSigFont('great')" class="flex-1 px-2 py-1 text-sm rounded-lg border border-indigo-200 bg-indigo-50 text-indigo-700 font-bold cursor-pointer sig-font-btn" data-font="great" style="font-family:'Great Vibes',cursive">Great Vibes</button>
                                    <button type="button" onclick="previewSigFont('dancing')" class="flex-1 px-2 py-1 text-sm rounded-lg border border-slate-200 bg-white text-slate-600 font-bold cursor-pointer sig-font-btn" data-font="dancing" style="font-family:'Dancing Script',cursive">Dancing</button>
                                    <button type="button" onclick="previewSigFont('alex')" class="flex-1 px-2 py-1 text-sm rounded-lg border border-slate-200 bg-white text-slate-600 font-bold cursor-pointer sig-font-btn" data-font="alex" style="font-family:'Alex Brush',cursive">Alex Brush</button>
                                </div>
                            </div>

                            <!-- Live Preview -->
                            <div class="bg-white border-2 border-dashed border-slate-200 rounded-xl p-3 text-center">
                                <p class="text-sm text-slate-400 uppercase tracking-wider mb-1">Signature Preview</p>
                                <p id="sig_preview" class="sig-preview sig-type-great">—</p>
                            </div>
                        </div>

                        <!-- ── Option 2: Upload Signature ── -->
                        <div id="sig-upload-fields" class="hidden space-y-2">
                            <div class="bg-slate-50 border border-dashed border-slate-300 rounded-xl p-4 text-center">
                                <div class="text-2xl mb-1">📷</div>
                                <p class="text-sm text-slate-500 font-semibold mb-2">Upload handwritten signature</p>
                                <label class="inline-block px-3 py-1.5 bg-white border border-slate-200 rounded-lg text-sm font-bold text-slate-600 hover:bg-slate-50 cursor-pointer transition">
                                    Choose JPG/PNG
                                    <input type="file" name="signature_file" accept=".jpg,.jpeg,.png" class="hidden">
                                </label>
                                <p class="text-sm text-slate-400 mt-1.5">Max 2MB • JPG or PNG only</p>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ REJECTION REASON (hidden by default) ═══ -->
                    <div id="reject-section" class="hidden border-t border-red-100 pt-4">
                        <div class="bg-red-50 rounded-xl p-4 border border-red-200 space-y-3">
                            <div class="flex items-center gap-2">
                                <span class="text-red-500 text-sm">⚠️</span>
                                <span class="text-sm font-bold text-red-700">Reason for Rejection</span>
                            </div>
                            <textarea name="reject_reason" id="reject_reason" rows="3" placeholder="Explain why this report needs revision…"
                                class="w-full bg-white border border-red-200 rounded-xl px-3 py-2 text-xs text-slate-700 focus:outline-none focus:border-red-400 transition resize-none"></textarea>
                            <p class="text-sm text-red-400">This reason will be shown to the student.</p>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex gap-2 pt-1">
                        <button type="submit" name="submit_feedback" class="flex-1 px-4 py-2.5 bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer">
                            📤 Approve
                        </button>
                        <button type="button" onclick="toggleReject()" id="reject-toggle-btn"
                            class="flex-1 px-4 py-2.5 bg-white border border-red-200 text-red-600 font-bold text-xs rounded-xl hover:bg-red-50 transition cursor-pointer">
                            ❌ Reject
                        </button>
                    </div>
                    <!-- Hidden reject submit (activated when reject mode is on) -->
                    <button type="submit" name="reject_report" id="reject-submit-btn" class="hidden w-full px-4 py-2.5 bg-red-600 hover:bg-red-700 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer">
                        ❌ Confirm Rejection
                    </button>
                </form>
                <?php endif; ?>

                <!-- Info Footer -->
                <div class="px-5 py-3 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                    <p class="text-sm text-slate-400 text-center leading-relaxed">
                        Signing sets status to <strong>Approved by Instructor</strong> and forwards to University Supervisor.
                    </p>
                </div>
            </div>
        </div>

    </div>

    <div class="text-center text-sm text-slate-300 py-2">Powered by InternReport</div>
</div>

<script>
// Toggle signature type buttons styling
function switchSigType(type) {
    var typed  = document.getElementById('sig-typed-fields');
    var upload = document.getElementById('sig-upload-fields');
    var hidden = document.getElementById('sig_type_input');
    var btnT   = document.getElementById('btn-typed');
    var btnU   = document.getElementById('btn-uploaded');
    var fontSel = document.getElementById('sig_font_select');

    if (type === 'typed') {
        typed.classList.remove('hidden');
        upload.classList.add('hidden');
        hidden.value = 'typed';
        if (fontSel) fontSel.classList.remove('hidden');
        btnT.className = 'flex-1 px-2 py-1.5 text-sm font-bold rounded-lg border transition cursor-pointer bg-indigo-600 text-white border-indigo-600';
        btnU.className = 'flex-1 px-2 py-1.5 text-sm font-bold rounded-lg border transition cursor-pointer bg-white text-slate-600 border-slate-200 hover:bg-slate-50';
    } else {
        typed.classList.add('hidden');
        upload.classList.remove('hidden');
        hidden.value = 'uploaded';
        if (fontSel) fontSel.classList.add('hidden');
        btnU.className = 'flex-1 px-2 py-1.5 text-sm font-bold rounded-lg border transition cursor-pointer bg-indigo-600 text-white border-indigo-600';
        btnT.className = 'flex-1 px-2 py-1.5 text-sm font-bold rounded-lg border transition cursor-pointer bg-white text-slate-600 border-slate-200 hover:bg-slate-50';
    }
}

// Font selector button active states
document.querySelectorAll('.sig-font-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.sig-font-btn').forEach(function(b) {
            b.className = b.className.replace('bg-indigo-50 text-indigo-700 border-indigo-200', 'bg-white text-slate-600 border-slate-200');
        });
        this.className = this.className.replace('bg-white text-slate-600 border-slate-200', 'bg-indigo-50 text-indigo-700 border-indigo-200');
    });
});

// Toggle rejection reason section
var rejectActive = false;
function toggleReject() {
    var section = document.getElementById('reject-section');
    var toggleBtn = document.getElementById('reject-toggle-btn');
    var submitBtn = document.getElementById('reject-submit-btn');
    rejectActive = !rejectActive;
    if (rejectActive) {
        section.classList.remove('hidden');
        toggleBtn.classList.add('hidden');
        submitBtn.classList.remove('hidden');
    } else {
        section.classList.add('hidden');
        toggleBtn.classList.remove('hidden');
        submitBtn.classList.add('hidden');
        document.getElementById('reject_reason').value = '';
    }
}
</script>

</body>
</html>
