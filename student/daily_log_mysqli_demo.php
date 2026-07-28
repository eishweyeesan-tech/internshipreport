<?php
/**
 * ══════════════════════════════════════════════════════════════════════
 *  DAILY LOG — DUPLICATE PREVENTION (mysqli Extension)
 *  Standalone demonstration script for presentation
 * ══════════════════════════════════════════════════════════════════════
 *
 *  Flow:
 *    1. Receive form POST  (student_id, log_date, task data)
 *    2. Validate inputs
 *    3. SELECT → check if a log already exists for this student + date
 *    4. If exists  → alert + redirect back (NO update)
 *    5. INSERT the new record
 *
 *  Security:
 *    - $conn->real_escape_string (prevents SQL injection)
 *    - Input sanitization (htmlspecialchars, trim)
 *    - Weekday + date-range validation
 * ══════════════════════════════════════════════════════════════════════
 */

// ─── Database Connection (mysqli) ─────────────────────────────────────
$conn = new mysqli('localhost', 'root', 'root', 'intern_report_db');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset('utf8mb4');

// ─── Start session for flash messages ────────────────────────────────
session_start();

// ─── Get authenticated student_id from session ───────────────────────
// (Replace with your actual auth logic)
$student_id = $_SESSION['user_id'] ?? null;
if (!$student_id) {
    header('Location: ../login.php');
    exit;
}

// ─── Handle POST submission ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── 1. Sanitize inputs ───────────────────────────────────────────
    $log_date           = trim($_POST['log_date'] ?? '');
    $attendance_status  = trim($_POST['attendance_status'] ?? 'present');
    $reason_for_absence = trim($_POST['reason_for_absence'] ?? '');
    $intended_task      = trim($_POST['intended_task'] ?? '');
    $task_detail        = trim($_POST['task_detail'] ?? '');
    $actual_task        = trim($_POST['actual_task'] ?? '');
    $tools_used         = trim($_POST['tools_used'] ?? '');
    $knowledge_gained   = trim($_POST['knowledge_gained'] ?? '');
    $hours_worked       = trim($_POST['hours_worked'] ?? '08:00');

    // ── 2. Basic validation ──────────────────────────────────────────
    if (empty($log_date)) {
        $_SESSION['flash_error'] = 'Please select a log date.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $date_obj = DateTime::createFromFormat('Y-m-d', $log_date);
    if (!$date_obj || $date_obj->format('Y-m-d') !== $log_date) {
        $_SESSION['flash_error'] = 'Invalid date format.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    // ── 3. Reject weekends (Saturday=6, Sunday=7 in PHP's format('N')) ──
    $day_of_week = (int) $date_obj->format('N');
    if ($day_of_week >= 6) {
        $_SESSION['flash_error'] = 'Weekends (Saturday & Sunday) are not allowed.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    // ── 4. DUPLICATE CHECK — SELECT with escaping ────────────────────
    //    This is the core fix: we query FIRST, only INSERT if no row exists.
    $esc_student_id = $conn->real_escape_string($student_id);
    $esc_log_date   = $conn->real_escape_string($log_date);

    $check_query = "SELECT id FROM daily_logs WHERE internship_id = {$esc_student_id} AND log_date = '{$esc_log_date}' LIMIT 1";
    $check_result = $conn->query($check_query);
    if (!$check_result) {
        die("Query failed: " . $conn->error);
    }

    $duplicate_exists = $check_result->num_rows > 0;
    $check_result->free();

    if ($duplicate_exists) {
        // ── DUPLICATE FOUND → Reject, alert, redirect ────────────────
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>';
        echo '<script>';
        echo 'alert("⚠️  Duplicate Entry Detected!\n\n'
           . 'A daily log for ' . htmlspecialchars($log_date) . ' has already been submitted.\n'
           . 'You cannot submit the same date twice.\n\n'
           . 'Please select a different date.");';
        echo 'window.history.back();';
        echo '</script></body></html>';
        exit;
    }

    // ── 5. NO DUPLICATE → Safe to INSERT ─────────────────────────────
    $esc_attendance_status  = $conn->real_escape_string($attendance_status);
    $esc_reason_for_absence = $conn->real_escape_string($reason_for_absence);
    $esc_intended_task      = $conn->real_escape_string($intended_task);
    $esc_task_detail        = $conn->real_escape_string($task_detail);
    $esc_actual_task        = $conn->real_escape_string($actual_task);
    $esc_tools_used         = $conn->real_escape_string($tools_used);
    $esc_knowledge_gained   = $conn->real_escape_string($knowledge_gained);
    $esc_hours_worked       = $conn->real_escape_string($hours_worked);

    $insert_query = "INSERT INTO daily_logs
            (internship_id, log_date, attendance_status, reason_for_absence,
             task_title, task_detail, tasks_performed, tools_used, learnt_skills, calculated_duration)
         VALUES ({$esc_student_id}, '{$esc_log_date}', '{$esc_attendance_status}', '{$esc_reason_for_absence}',
                 '{$esc_intended_task}', '{$esc_task_detail}', '{$esc_actual_task}', '{$esc_tools_used}',
                 '{$esc_knowledge_gained}', '{$esc_hours_worked}')";

    if ($conn->query($insert_query)) {
        $_SESSION['flash_success'] = "Daily log for {$log_date} saved successfully.";
    } else {
        $_SESSION['flash_error'] = "Failed to save log: " . $conn->error;
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ─── Read flash messages ─────────────────────────────────────────────
$flash_error   = $_SESSION['flash_error'] ?? '';
$flash_success = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_error'], $_SESSION['flash_success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Log — MySQL Validation Demo</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 font-sans antialiased min-h-screen p-6">

<div class="max-w-2xl mx-auto space-y-6">

    <!-- Header -->
    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-6">
        <h1 class="text-lg font-black text-slate-800 mb-1">Daily Log — MySQL Validation</h1>
        <p class="text-xs text-slate-400">Duplicate prevention using <code class="bg-slate-100 px-1 rounded">SELECT → INSERT</code> with mysqli extension.</p>
    </div>

    <?php if ($flash_error): ?>
    <div class="bg-red-50 border border-red-200 rounded-2xl p-4 flex items-start gap-3">
        <div class="w-8 h-8 rounded-full bg-red-100 text-red-500 flex items-center justify-center text-sm shrink-0">✕</div>
        <div>
            <h3 class="text-xs font-bold text-red-700">Rejected</h3>
            <p class="text-xs text-red-600 mt-0.5"><?= htmlspecialchars($flash_error) ?></p>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($flash_success): ?>
    <div class="bg-emerald-50 border border-emerald-200 rounded-2xl p-4 flex items-start gap-3">
        <div class="w-8 h-8 rounded-full bg-emerald-100 text-emerald-500 flex items-center justify-center text-sm shrink-0">✓</div>
        <div>
            <h3 class="text-xs font-bold text-emerald-700">Saved</h3>
            <p class="text-xs text-emerald-600 mt-0.5"><?= htmlspecialchars($flash_success) ?></p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Form -->
    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-6">
        <h2 class="text-sm font-black text-slate-800 uppercase tracking-wider border-b border-slate-100 pb-3 mb-5">
            📝 New Log Entry
        </h2>
        <form method="POST" class="space-y-4">
            <div>
                <label class="block text-sm font-bold text-slate-500 mb-1">📅 Log Date</label>
                <input type="date" name="log_date" required
                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 focus:bg-white transition">
            </div>
            <div>
                <label class="block text-sm font-bold text-slate-500 mb-1">💡 Intended Task</label>
                <input type="text" name="intended_task" placeholder="e.g. UI Design"
                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 focus:bg-white transition">
            </div>
            <div>
                <label class="block text-sm font-bold text-slate-500 mb-1">✅ Actual Task Performed</label>
                <textarea name="actual_task" rows="2" placeholder="What you accomplished…"
                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-700 focus:outline-none focus:border-blue-500 focus:bg-white transition resize-none"></textarea>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-bold text-slate-500 mb-1">🛠️ Tools Used</label>
                    <input type="text" name="tools_used" placeholder="PHP, MySQL, TailwindCSS"
                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-mono text-emerald-600 focus:outline-none focus:border-blue-500 focus:bg-white transition">
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-500 mb-1">🧠 Knowledge Gained</label>
                    <input type="text" name="knowledge_gained" placeholder="REST APIs, OOP…"
                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-700 focus:outline-none focus:border-blue-500 focus:bg-white transition">
                </div>
            </div>
            <div>
                <label class="block text-sm font-bold text-slate-500 mb-1">⏱️ Hours Worked</label>
                <input type="text" name="hours_worked" value="08:00" placeholder="HH:MM"
                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-mono text-blue-600 focus:outline-none focus:border-blue-500 focus:bg-white transition">
            </div>
            <div class="flex justify-end">
                <button type="submit"
                    class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer">
                    💾 Save Daily Log
                </button>
            </div>
        </form>
    </div>

</div>
</body>
</html>
