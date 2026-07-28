<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth.php';

if ($_SESSION['role'] !== 'admin') {
    header('Location: ../dashboard.php');
    exit;
}

$msg = '';
$err = '';

// ══════════════════════════════════════════════════════════════════
// ARCHIVED YEAR FILTER (resolved early for POST redirects)
// ══════════════════════════════════════════════════════════════════
$_archived_years_raw = $pdo->query("
    SELECT id, year_label
    FROM academic_years
    WHERE status = 'ARCHIVED' AND is_current = 0
    ORDER BY start_date DESC
")->fetchAll();

$selected_archived_year_id = isset($_GET['archived_year_id']) ? (int) $_GET['archived_year_id'] : 0;
if ($selected_archived_year_id > 0) {
    $valid_ay_ids = array_column($_archived_years_raw, 'id');
    if (!in_array($selected_archived_year_id, $valid_ay_ids, false)) {
        $selected_archived_year_id = 0;
    }
}
$year_qs = $selected_archived_year_id > 0 ? '&archived_year_id=' . $selected_archived_year_id : '';

$selected_year_label = '';
if ($selected_archived_year_id > 0) {
    foreach ($_archived_years_raw as $_ay) {
        if ((int) $_ay['id'] === $selected_archived_year_id) {
            $selected_year_label = $_ay['year_label'];
            break;
        }
    }
}

// ══════════════════════════════════════════════════════════════════
// HANDLERS
// ══════════════════════════════════════════════════════════════════

// ── Add Past Year Daily Log ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_past_log'])) {
    $l_student_id = (int) ($_POST['l_student_id'] ?? 0);
    $l_date       = trim($_POST['l_date'] ?? '');
    $l_status     = trim($_POST['l_status'] ?? 'present');
    $l_reason     = trim($_POST['l_reason'] ?? '');
    $l_task       = trim($_POST['l_task'] ?? '');
    $l_detail     = trim($_POST['l_detail'] ?? '');
    $l_actual     = trim($_POST['l_actual'] ?? '');
    $l_tools      = trim($_POST['l_tools'] ?? '');
    $l_skills     = trim($_POST['l_skills'] ?? '');
    $l_duration   = trim($_POST['l_duration'] ?? '08:00');

    if (!$l_student_id || !$l_date) {
        $err = 'Student and date are required for a daily log.';
    } else {
        $esc_sid = $pdo->prepare("SELECT id FROM student_profiles WHERE user_id = ?");
        $esc_sid->execute([$l_student_id]);
        $iid = $esc_sid->fetchColumn();
        if (!$iid) {
            $err = 'Student profile not found.';
        } else {
            if ($l_status === 'absent') {
                $l_task   = $l_reason ?: 'Absent';
                $l_detail = 'N/A - Absent';
                $l_actual = 'N/A - Absent';
                $l_tools  = 'N/A - Absent';
                $l_skills = 'N/A - Absent';
                $l_duration = '00:00';
            }
            $pdo->prepare("INSERT INTO daily_logs (internship_id, log_date, attendance_status, reason_for_absence, task_title, task_detail, tasks_performed, tools_used, learnt_skills, calculated_duration)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE attendance_status=VALUES(attendance_status), reason_for_absence=VALUES(reason_for_absence), task_title=VALUES(task_title), task_detail=VALUES(task_detail), tasks_performed=VALUES(tasks_performed), tools_used=VALUES(tools_used), learnt_skills=VALUES(learnt_skills), calculated_duration=VALUES(calculated_duration)")
                ->execute([$iid, $l_date, $l_status, $l_reason, $l_task, $l_detail, $l_actual, $l_tools, $l_skills, $l_duration]);
            $msg = "Daily log for {$l_date} saved.";
            header('Location: past-year.php?tab=logs&sid=' . $l_student_id . $year_qs . '&msg=' . urlencode($msg));
            exit;
        }
    }
}

// ── Add Past Year Reflection ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_past_reflection'])) {
    $r_student_id = (int) ($_POST['r_student_id'] ?? 0);
    $r_week       = (int) ($_POST['r_week'] ?? 0);
    $r_what       = trim($_POST['r_what'] ?? '');
    $r_how        = trim($_POST['r_how'] ?? '');
    $r_why        = trim($_POST['r_why'] ?? '');

    if (!$r_student_id || $r_week < 1 || empty($r_what)) {
        $err = 'Student, week number, and "What was done" are required.';
    } else {
        $esc_sid = $pdo->prepare("SELECT id FROM student_profiles WHERE user_id = ?");
        $esc_sid->execute([$r_student_id]);
        $iid = $esc_sid->fetchColumn();
        if (!$iid) {
            $err = 'Student profile not found.';
        } else {
            $pdo->prepare("INSERT INTO weekly_reflections (internship_id, week_number, what_done, how_done, why_done)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE what_done=VALUES(what_done), how_done=VALUES(how_done), why_done=VALUES(why_done)")
                ->execute([$iid, $r_week, $r_what, $r_how, $r_why]);
            $msg = "Weekly reflection for Week {$r_week} saved.";
            header('Location: past-year.php?tab=reflections&sid=' . $r_student_id . $year_qs . '&msg=' . urlencode($msg));
            exit;
        }
    }
}

// ── Add Past Year Evaluation ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_past_eval'])) {
    $e_student_id = (int) ($_POST['e_student_id'] ?? 0);
    $e_week       = (int) ($_POST['e_week'] ?? 0);
    $e_grade      = trim($_POST['e_grade'] ?? 'good');
    $e_comment    = trim($_POST['e_comment'] ?? '');
    $e_status     = trim($_POST['e_status'] ?? 'approved_by_instructor');

    if (!$e_student_id || $e_week < 1) {
        $err = 'Student and week number are required.';
    } else {
        $valid_grades = ['excellent', 'good', 'average', 'needs_improvement'];
        if (!in_array($e_grade, $valid_grades)) $e_grade = 'good';
        $valid_statuses = ['pending', 'approved_by_instructor', 'approved_by_supervisor', 'rejected'];
        if (!in_array($e_status, $valid_statuses)) $e_status = 'approved_by_instructor';

        $pdo->prepare("INSERT INTO report_evaluations (student_id, week_number, grade, comment, report_status, evaluated_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE grade=VALUES(grade), comment=VALUES(comment), report_status=VALUES(report_status), evaluated_at=NOW()")
            ->execute([$e_student_id, $e_week, $e_grade, $e_comment, $e_status]);
        $msg = "Evaluation for Week {$e_week} saved.";
            header('Location: past-year.php?tab=evals&sid=' . $e_student_id . $year_qs . '&msg=' . urlencode($msg));
            exit;
    }
}

// ── Delete Past Log ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_past_log'])) {
    $dl_id = (int) ($_POST['log_id'] ?? 0);
    $dl_sid = (int) ($_POST['student_id'] ?? 0);
    if ($dl_id > 0) {
        $pdo->prepare("DELETE FROM daily_logs WHERE id = ?")->execute([$dl_id]);
        $msg = 'Log deleted.';
        header('Location: past-year.php?tab=logs&sid=' . $dl_sid . $year_qs . '&msg=' . urlencode($msg));
        exit;
    }
}

// ── Delete Past Reflection ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_past_ref'])) {
    $dr_id = (int) ($_POST['ref_id'] ?? 0);
    $dr_sid = (int) ($_POST['student_id'] ?? 0);
    if ($dr_id > 0) {
        $pdo->prepare("DELETE FROM weekly_reflections WHERE id = ?")->execute([$dr_id]);
        $msg = 'Reflection deleted.';
        header('Location: past-year.php?tab=reflections&sid=' . $dr_sid . $year_qs . '&msg=' . urlencode($msg));
        exit;
    }
}

// ── Delete Past Evaluation ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_past_eval'])) {
    $de_id = (int) ($_POST['eval_id'] ?? 0);
    $de_sid = (int) ($_POST['student_id'] ?? 0);
    if ($de_id > 0) {
        $pdo->prepare("DELETE FROM report_evaluations WHERE id = ?")->execute([$de_id]);
        $msg = 'Evaluation deleted.';
        header('Location: past-year.php?tab=evals&sid=' . $de_sid . $year_qs . '&msg=' . urlencode($msg));
        exit;
    }
}

$msg = $_GET['msg'] ?? $msg;

// ══════════════════════════════════════════════════════════════════
// DATA QUERIES
// ══════════════════════════════════════════════════════════════════

$tab = $_GET['tab'] ?? 'directory';
if (!in_array($tab, ['directory', 'logs', 'reflections', 'evals'])) $tab = 'directory';

$admin_name = $_SESSION['username'] ?? 'Admin';

// Archived years (from dimension table)
$archived_years = $_archived_years_raw;

// Archived students count (filtered by year if selected)
if ($selected_archived_year_id > 0) {
    $cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'Archived' AND academic_year_id = ?");
    $cnt_stmt->execute([$selected_archived_year_id]);
    $archived_students_count = (int) $cnt_stmt->fetchColumn();
} else {
    $archived_students_count = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'Archived'")->fetchColumn();
}

// Archived logs count (filtered by year if selected)
if ($selected_archived_year_id > 0) {
    $logs_stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM daily_logs dl
        JOIN student_profiles sp ON sp.id = dl.internship_id
        JOIN users u ON u.id = sp.user_id
        WHERE u.role = 'student' AND u.status = 'Archived'
          AND u.academic_year_id = ?
    ");
    $logs_stmt->execute([$selected_archived_year_id]);
    $archived_logs_count = (int) $logs_stmt->fetchColumn();
} else {
    $archived_logs_count = (int) $pdo->query("
        SELECT COUNT(*)
        FROM daily_logs dl
        JOIN student_profiles sp ON sp.id = dl.internship_id
        JOIN users u ON u.id = sp.user_id
        WHERE u.role = 'student' AND u.status = 'Archived'
    ")->fetchColumn();
}

// Archived companies count (filtered by year if selected)
if ($selected_archived_year_id > 0) {
    $comp_stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT sp.company_name)
        FROM student_profiles sp
        JOIN users u ON u.id = sp.user_id
        WHERE u.role = 'student' AND u.status = 'Archived'
          AND u.academic_year_id = ?
          AND sp.company_name IS NOT NULL AND sp.company_name != ''
    ");
    $comp_stmt->execute([$selected_archived_year_id]);
    $archived_companies_count = (int) $comp_stmt->fetchColumn();
} else {
    $archived_companies_count = (int) $pdo->query("
        SELECT COUNT(DISTINCT sp.company_name)
        FROM student_profiles sp
        JOIN users u ON u.id = sp.user_id
        WHERE u.role = 'student' AND u.status = 'Archived'
          AND sp.company_name IS NOT NULL AND sp.company_name != ''
    ")->fetchColumn();
}

// Archived supervisors count (filtered by year if selected)
if ($selected_archived_year_id > 0) {
    $sup_stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT sa.supervisor_id)
        FROM supervisor_assignments sa
        JOIN users u ON u.id = sa.supervisor_id
        WHERE u.role = 'supervisor'
          AND sa.academic_year_id = ?
    ");
    $sup_stmt->execute([$selected_archived_year_id]);
    $archived_supervisors_count = (int) $sup_stmt->fetchColumn();
} else {
    $archived_supervisors_count = (int) $pdo->query("
        SELECT COUNT(DISTINCT sa.supervisor_id)
        FROM supervisor_assignments sa
        JOIN users u ON u.id = sa.supervisor_id
        WHERE u.role = 'supervisor'
    ")->fetchColumn();
}

// All past students (archived, optionally filtered by selected year)
if ($selected_archived_year_id > 0) {
    $ps_stmt = $pdo->prepare("
        SELECT u.id, u.username, u.email, u.academic_year, u.academic_year_id,
               sp.full_name, sp.student_roll, sp.company_name,
               sp.internship_start_date, sp.internship_end_date
        FROM users u
        LEFT JOIN student_profiles sp ON sp.user_id = u.id
        WHERE u.role = 'student' AND u.status = 'Archived'
          AND u.academic_year_id = ?
        ORDER BY sp.full_name ASC
    ");
    $ps_stmt->execute([$selected_archived_year_id]);
    $all_past_students = $ps_stmt->fetchAll();
} else {
    $all_past_students = $pdo->query("
        SELECT u.id, u.username, u.email, u.academic_year, u.academic_year_id,
               sp.full_name, sp.student_roll, sp.company_name,
               sp.internship_start_date, sp.internship_end_date
        FROM users u
        LEFT JOIN student_profiles sp ON sp.user_id = u.id
        WHERE u.role = 'student' AND u.status = 'Archived'
        ORDER BY u.academic_year_id DESC, sp.full_name ASC
    ")->fetchAll();
}

// Selected student data (for tabs)
$selected_student_id = (int) ($_GET['sid'] ?? 0);
$selected_student = null;
$student_logs = [];
$student_refs = [];
$student_evals = [];

if ($selected_student_id > 0) {
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.email, u.academic_year,
               sp.full_name, sp.student_roll, sp.major, sp.company_name,
               sp.instructor_name, sp.internship_start_date, sp.internship_end_date
        FROM users u
        LEFT JOIN student_profiles sp ON sp.user_id = u.id
        WHERE u.id = ? AND u.role = 'student' AND u.status = 'Archived'
    ");
    $stmt->execute([$selected_student_id]);
    $selected_student = $stmt->fetch();

    if ($selected_student) {
        $iid_stmt = $pdo->prepare("SELECT id FROM student_profiles WHERE user_id = ?");
        $iid_stmt->execute([$selected_student_id]);
        $iid = $iid_stmt->fetchColumn();

        if ($iid) {
            $logs_stmt = $pdo->prepare("SELECT * FROM daily_logs WHERE internship_id = ? ORDER BY log_date ASC");
            $logs_stmt->execute([$iid]);
            $student_logs = $logs_stmt->fetchAll();

            $refs_stmt = $pdo->prepare("SELECT * FROM weekly_reflections WHERE internship_id = ? ORDER BY week_number ASC");
            $refs_stmt->execute([$iid]);
            $student_refs = $refs_stmt->fetchAll();

            $evals_stmt = $pdo->prepare("SELECT * FROM report_evaluations WHERE student_id = ? ORDER BY week_number ASC");
            $evals_stmt->execute([$selected_student_id]);
            $student_evals = $evals_stmt->fetchAll();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Past Year Data – Admin – InternReport</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
    tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                fontFamily: {
                    'inter': ['Inter', 'sans-serif'],
                },
                fontSize: {
                    'micro': '0.5rem',
                    'caption': '0.6875rem',
                    'label': '0.8125rem',
                    'subtitle': '0.9375rem',
                    'body': '1rem',
                },
            }
        }
    }
    </script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/material_blue.css">
    <style>
        .flatpickr-calendar { border-radius: 0.75rem !important; border: 1px solid #e2e8f0 !important; box-shadow: 0 10px 25px -5px rgba(0,0,0,.1) !important; }
        .flatpickr-months .flatpickr-month { background: linear-gradient(to right, #6366f1, #8b5cf6); color: #fff; }
        .flatpickr-current-month .flatpickr-monthDropdown-months,
        .flatpickr-current-month input.cur-year { color: #fff; font-weight: 700; }
        span.flatpickr-weekday { color: #64748b; font-size: 0.8125rem; font-weight: 700; }
        .flatpickr-day { border-radius: 0.5rem !important; font-size: 0.8125rem; font-weight: 500; margin: 2px !important; width: 34px; height: 34px; line-height: 34px; }
        .flatpickr-day.selected, .flatpickr-day.selected:hover { background: #6366f1 !important; border-color: #6366f1 !important; }
        .flatpickr-prev-month, .flatpickr-next-month { fill: #fff !important; stroke: #fff !important; }
    </style>
</head>
<body class="bg-slate-50 font-sans antialiased">

<div class="flex h-screen overflow-hidden">

    <?php $activePage = 'past-year'; ?>
    <?php require_once __DIR__ . '/../includes/admin-sidebar.php'; ?>

    <!-- ─── MAIN ─── -->
    <div class="flex-1 flex flex-col overflow-hidden">

        <!-- Top Bar -->
        <?php
        $pageTitle = 'Past Academic Years';
        $current_tab = $tab;
        $hide_current_session = true;
        require_once __DIR__ . '/../includes/topbar-with-year-filter.php';
        ?>

        <main class="flex-1 overflow-y-auto p-6">

            <?php if ($msg): ?>
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm font-semibold px-4 py-3 rounded-xl flex items-center gap-2 mb-6">
                <span>✅</span> <?= htmlspecialchars($msg) ?>
            </div>
            <?php endif; ?>
            <?php if ($err): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 text-sm font-semibold px-4 py-3 rounded-xl flex items-center gap-2 mb-6">
                <span>❌</span> <?= htmlspecialchars($err) ?>
            </div>
            <?php endif; ?>

            <div class="max-w-6xl mx-auto space-y-6">

                <!-- Tab Navigation -->
                <div class="flex items-center gap-2 flex-wrap">
                    <a href="?tab=directory<?= $year_qs ?>" class="px-4 py-2 text-xs font-bold rounded-xl transition <?= $tab === 'directory' ? 'bg-indigo-600 text-white shadow-sm' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">📋 Directory</a>
                    <a href="?tab=logs<?= $year_qs ?>" class="px-4 py-2 text-xs font-bold rounded-xl transition <?= $tab === 'logs' ? 'bg-indigo-600 text-white shadow-sm' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">📝 Daily Logs</a>
                    <a href="?tab=reflections<?= $year_qs ?>" class="px-4 py-2 text-xs font-bold rounded-xl transition <?= $tab === 'reflections' ? 'bg-indigo-600 text-white shadow-sm' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">📊 Reflections</a>
                    <a href="?tab=evals<?= $year_qs ?>" class="px-4 py-2 text-xs font-bold rounded-xl transition <?= $tab === 'evals' ? 'bg-indigo-600 text-white shadow-sm' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">⭐ Evaluations</a>
                </div>

                <!-- ════════════════════════════════════════════════════ -->
                <!-- TAB: DIRECTORY -->
                <!-- ════════════════════════════════════════════════════ -->
                <?php if ($tab === 'directory'): ?>
                <!-- Summary Stats -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                    <div class="p-4 bg-white border border-slate-100 rounded-2xl shadow-sm flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-lg shrink-0">📋</div>
                        <div>
                            <p class="text-2xl font-black text-slate-800 leading-none"><?= $archived_students_count ?></p>
                            <p class="text-label font-bold text-slate-400 uppercase mt-1">Total Archived Students</p>
                        </div>
                    </div>
                    <div class="p-4 bg-white border border-slate-100 rounded-2xl shadow-sm flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-lg shrink-0">📝</div>
                        <div>
                            <p class="text-2xl font-black text-slate-800 leading-none"><?= $archived_logs_count ?></p>
                            <p class="text-label font-bold text-slate-400 uppercase mt-1">Total Archived Logs</p>
                        </div>
                    </div>
                    <div class="p-4 bg-white border border-slate-100 rounded-2xl shadow-sm flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-lg shrink-0">🏢</div>
                        <div>
                            <p class="text-2xl font-black text-slate-800 leading-none"><?= $archived_companies_count ?></p>
                            <p class="text-label font-bold text-slate-400 uppercase mt-1">Total Archived Companies</p>
                        </div>
                    </div>
                    <div class="p-4 bg-white border border-slate-100 rounded-2xl shadow-sm flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-violet-50 text-violet-600 flex items-center justify-center text-lg shrink-0">👤</div>
                        <div>
                            <p class="text-2xl font-black text-slate-800 leading-none"><?= $archived_supervisors_count ?></p>
                            <p class="text-label font-bold text-slate-400 uppercase mt-1">Total Archived Supervisors</p>
                        </div>
                    </div>
                </div>

                <!-- Archived Students Directory -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden mt-6">
                    <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
                        <h2 class="text-sm font-black text-slate-800 uppercase tracking-wider flex items-center gap-2">
                            <span class="p-1 bg-indigo-50 text-indigo-600 rounded">👤</span> Archived Students Directory
                        </h2>
                        <span class="text-label font-bold text-slate-400"><?= count($all_past_students) ?> students</span>
                    </div>
                    
                    <?php if (empty($all_past_students)): ?>
                    <div class="p-8 text-center">
                        <div class="w-16 h-16 rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center text-3xl mx-auto mb-4">📭</div>
                        <p class="text-sm font-semibold text-slate-700 mb-1">No archived students yet</p>
                        <p class="text-xs text-slate-400 max-w-sm mx-auto">No archived records found for the selected academic year(s).</p>
                    </div>
                    <?php else: ?>
                    <!-- Group by Academic Year -->
                    <?php
                    $grouped = [];
                    foreach ($all_past_students as $ps) {
                        $ay = $ps['academic_year'] ?? 'Unknown Year';
                        $grouped[$ay][] = $ps;
                    }
                    ?>
                    <?php foreach ($grouped as $ay => $students): ?>
                    <div class="border-b border-slate-100 last:border-b-0">
                        <div class="px-5 py-2.5 bg-slate-50/80 flex items-center gap-2">
                            <span class="text-xs font-black text-indigo-600">📅 <?= htmlspecialchars($ay) ?></span>
                            <span class="text-label font-bold text-slate-400 bg-slate-200 px-1.5 py-0.5 rounded"><?= count($students) ?> student<?= count($students) > 1 ? 's' : '' ?></span>
                        </div>
                        <div class="divide-y divide-slate-100">
                            <?php foreach ($students as $ps): ?>
                            <div class="px-5 py-3 hover:bg-slate-50/50 transition flex items-center gap-4">
                                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center text-sm font-bold shrink-0">
                                    <?= strtoupper(($ps['full_name'] ?: $ps['username'])[0]) ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-bold text-slate-800"><?= htmlspecialchars($ps['full_name'] ?: $ps['username']) ?></p>
                                    <p class="text-label text-slate-400 mt-0.5">
                                        <?= htmlspecialchars($ps['student_roll'] ?: $ps['username']) ?>
                                        <?php if ($ps['company_name']): ?>
                                        <span class="mx-1">·</span> 🏢 <?= htmlspecialchars($ps['company_name']) ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <div class="text-right shrink-0">
                                    <?php if ($ps['internship_start_date']): ?>
                                    <p class="text-label font-bold text-slate-500">
                                        <?= (new DateTime($ps['internship_start_date']))->format('d M Y') ?>
                                        <?= $ps['internship_end_date'] ? ' – ' . (new DateTime($ps['internship_end_date']))->format('d M Y') : '' ?>
                                    </p>
                                    <?php endif; ?>
                                </div>
                                <div class="flex items-center gap-1 shrink-0">
                                    <a href="?tab=logs&sid=<?= $ps['id'] ?><?= $year_qs ?>" class="px-2 py-1 bg-blue-50 text-blue-600 text-label font-bold rounded-lg hover:bg-blue-100 transition" title="View Logs">📝</a>
                                    <a href="?tab=reflections&sid=<?= $ps['id'] ?><?= $year_qs ?>" class="px-2 py-1 bg-purple-50 text-purple-600 text-label font-bold rounded-lg hover:bg-purple-100 transition" title="View Reflections">📊</a>
                                    <a href="?tab=evals&sid=<?= $ps['id'] ?><?= $year_qs ?>" class="px-2 py-1 bg-amber-50 text-amber-600 text-label font-bold rounded-lg hover:bg-amber-100 transition" title="View Evaluations">⭐</a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php endif; ?>

                <?php if ($tab === 'logs' || $tab === 'reflections' || $tab === 'evals'): ?>
                <!-- Student Selector (for data entry tabs) -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
                    <label class="block text-xs font-bold text-slate-500 mb-2">Select Archived Student</label>
                    <form method="GET" class="flex items-end gap-3">
                        <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
                        <?php if ($selected_archived_year_id > 0): ?>
                        <input type="hidden" name="archived_year_id" value="<?= $selected_archived_year_id ?>">
                        <?php endif; ?>
                        <select name="sid" onchange="this.form.submit()" class="flex-1 bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-indigo-500 transition">
                            <option value="">— Choose a student —</option>
                            <?php
                            $grouped = [];
                            foreach ($all_past_students as $ps) {
                                $ay = $ps['academic_year'] ?? 'Unknown';
                                $grouped[$ay][] = $ps;
                            }
                            foreach ($grouped as $ay => $students):
                            ?>
                            <optgroup label="<?= htmlspecialchars($ay) ?>">
                                <?php foreach ($students as $ps): ?>
                                <option value="<?= $ps['id'] ?>" <?= $selected_student_id == $ps['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($ps['full_name'] ?: $ps['username']) ?> (<?= htmlspecialchars($ps['student_roll'] ?: $ps['email']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>

                <?php if ($selected_student): ?>
                <!-- Student Info Bar -->
                <div class="bg-gradient-to-r from-indigo-500 to-purple-600 rounded-2xl p-4 text-white flex items-center gap-4">
                    <div class="w-12 h-12 rounded-full bg-white/20 flex items-center justify-center text-lg font-bold shrink-0">
                        <?= strtoupper(($selected_student['full_name'] ?: 'S')[0]) ?>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-bold"><?= htmlspecialchars($selected_student['full_name'] ?: $selected_student['username']) ?></p>
                        <p class="text-label opacity-80"><?= htmlspecialchars($selected_student['student_roll'] ?: '') ?> · <?= htmlspecialchars($selected_student['academic_year'] ?: '') ?></p>
                    </div>
                    <div class="text-right text-label opacity-80 shrink-0">
                        <?php if ($selected_student['internship_start_date']): ?>
                        <?= (new DateTime($selected_student['internship_start_date']))->format('d M Y') ?> – <?= $selected_student['internship_end_date'] ? (new DateTime($selected_student['internship_end_date']))->format('d M Y') : 'N/A' ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ════════════════════════════════════════════════════ -->
                <!-- TAB: DAILY LOGS -->
                <!-- ════════════════════════════════════════════════════ -->
                <?php if ($tab === 'logs'): ?>
                <!-- Add Log Form -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-100">
                        <h2 class="text-sm font-black text-slate-800 uppercase tracking-wider flex items-center gap-2">
                            <span class="p-1 bg-blue-50 text-blue-600 rounded">📝</span> Add Daily Log
                        </h2>
                    </div>
                    <form method="POST" class="p-5 space-y-4">
                        <input type="hidden" name="add_past_log" value="1">
                        <input type="hidden" name="l_student_id" value="<?= $selected_student_id ?>">
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-500 mb-1">📅 Date *</label>
                                <input type="text" name="l_date" id="log_date_input" required placeholder="YYYY-MM-DD" class="fp-date w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-indigo-500 transition">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 mb-1">✅ Attendance</label>
                                <select name="l_status" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-indigo-500 transition">
                                    <option value="present">Present</option>
                                    <option value="absent">Absent / Leave</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 mb-1">⏳ Duration (HH:MM)</label>
                                <input type="text" name="l_duration" value="08:00" placeholder="08:00" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-mono text-slate-800 focus:outline-none focus:border-indigo-500 transition">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-500 mb-1">💡 Task Title</label>
                                <input type="text" name="l_task" placeholder="e.g. UI Design & API Integration" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-indigo-500 transition">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 mb-1">🛠️ Tools Used</label>
                                <input type="text" name="l_tools" placeholder="PHP, TailwindCSS, MySQL…" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-mono text-slate-800 focus:outline-none focus:border-indigo-500 transition">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 mb-1">📋 Task Detail</label>
                                <textarea name="l_detail" rows="2" placeholder="Describe the planned tasks…" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-700 focus:outline-none focus:border-indigo-500 transition resize-none"></textarea>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 mb-1">✅ Actual Task Performed</label>
                                <textarea name="l_actual" rows="2" placeholder="What was actually accomplished…" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-700 focus:outline-none focus:border-indigo-500 transition resize-none"></textarea>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 mb-1">🧠 Knowledge Gained</label>
                                <input type="text" name="l_skills" placeholder="Database optimization, REST APIs…" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-indigo-500 transition">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 mb-1">📝 Reason for Absence</label>
                                <input type="text" name="l_reason" placeholder="Only if absent…" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-indigo-500 transition">
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer">💾 Save Daily Log</button>
                        </div>
                    </form>
                </div>

                <!-- Existing Logs -->
                <?php if (!empty($student_logs)): ?>
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-100">
                        <h2 class="text-sm font-black text-slate-800 uppercase tracking-wider">📋 Existing Logs (<?= count($student_logs) ?>)</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead>
                                <tr class="bg-slate-50/80 text-slate-500 font-semibold uppercase tracking-wider">
                                    <th class="px-3 py-2.5 text-left">Date</th>
                                    <th class="px-3 py-2.5 text-left">Status</th>
                                    <th class="px-3 py-2.5 text-left">Task</th>
                                    <th class="px-3 py-2.5 text-left">Tools</th>
                                    <th class="px-3 py-2.5 text-center">Duration</th>
                                    <th class="px-3 py-2.5 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($student_logs as $log): ?>
                                <tr class="hover:bg-slate-50/50 transition">
                                    <td class="px-3 py-2 font-medium text-slate-700 whitespace-nowrap"><?= (new DateTime($log['log_date']))->format('D, d M Y') ?></td>
                                    <td class="px-3 py-2">
                                        <?php if ($log['attendance_status'] === 'present'): ?>
                                        <span class="text-label font-bold text-emerald-600 bg-emerald-50 px-1.5 py-0.5 rounded">✅ Present</span>
                                        <?php else: ?>
                                        <span class="text-label font-bold text-red-600 bg-red-50 px-1.5 py-0.5 rounded">❌ Absent</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 py-2 text-slate-600 max-w-[180px] truncate" title="<?= htmlspecialchars($log['task_title'] ?? '') ?>"><?= htmlspecialchars($log['task_title'] ?: '-') ?></td>
                                    <td class="px-3 py-2 text-slate-600 max-w-[120px] truncate"><?= htmlspecialchars($log['tools_used'] ?: '-') ?></td>
                                    <td class="px-3 py-2 text-center font-mono text-blue-600 font-bold"><?= htmlspecialchars($log['calculated_duration']) ?></td>
                                    <td class="px-3 py-2 text-right">
                                        <form method="POST" onsubmit="return confirm('Delete this log?')" class="inline">
                                            <input type="hidden" name="delete_past_log" value="1">
                                            <input type="hidden" name="log_id" value="<?= $log['id'] ?>">
                                            <input type="hidden" name="student_id" value="<?= $selected_student_id ?>">
                                            <button type="submit" class="px-2 py-1 bg-red-50 text-red-600 text-label font-bold rounded-lg hover:bg-red-100 transition cursor-pointer">🗑️</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- ════════════════════════════════════════════════════ -->
                <!-- TAB: REFLECTIONS -->
                <!-- ════════════════════════════════════════════════════ -->
                <?php elseif ($tab === 'reflections'): ?>
                <!-- Add Reflection Form -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-100">
                        <h2 class="text-sm font-black text-slate-800 uppercase tracking-wider flex items-center gap-2">
                            <span class="p-1 bg-purple-50 text-purple-600 rounded">📊</span> Add Weekly Reflection
                        </h2>
                    </div>
                    <form method="POST" class="p-5 space-y-4">
                        <input type="hidden" name="add_past_reflection" value="1">
                        <input type="hidden" name="r_student_id" value="<?= $selected_student_id ?>">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-500 mb-1">📅 Week Number *</label>
                                <input type="number" name="r_week" min="1" max="52" required placeholder="1" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-mono text-slate-800 focus:outline-none focus:border-indigo-500 transition">
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-xs font-bold text-slate-500 mb-1">💡 What was done? *</label>
                                <input type="text" name="r_what" required placeholder="e.g. Completed login module and user dashboard" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-indigo-500 transition">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-500 mb-1">🔧 How was it done?</label>
                                <textarea name="r_how" rows="2" placeholder="e.g. Used PHP and MySQL for backend, TailwindCSS for frontend…" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-700 focus:outline-none focus:border-indigo-500 transition resize-none"></textarea>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 mb-1">❓ Why was it done?</label>
                                <textarea name="r_why" rows="2" placeholder="e.g. To enable secure user authentication and role-based access…" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-700 focus:outline-none focus:border-indigo-500 transition resize-none"></textarea>
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" class="px-5 py-2 bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer">💾 Save Reflection</button>
                        </div>
                    </form>
                </div>

                <!-- Existing Reflections -->
                <?php if (!empty($student_refs)): ?>
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-100">
                        <h2 class="text-sm font-black text-slate-800 uppercase tracking-wider">📊 Existing Reflections (<?= count($student_refs) ?>)</h2>
                    </div>
                    <div class="divide-y divide-slate-100">
                        <?php foreach ($student_refs as $ref): ?>
                        <div class="px-5 py-4">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-xs font-bold text-purple-600 bg-purple-50 px-2 py-0.5 rounded">Week <?= $ref['week_number'] ?></span>
                                <form method="POST" onsubmit="return confirm('Delete this reflection?')" class="inline">
                                    <input type="hidden" name="delete_past_ref" value="1">
                                    <input type="hidden" name="ref_id" value="<?= $ref['id'] ?>">
                                    <input type="hidden" name="student_id" value="<?= $selected_student_id ?>">
                                    <button type="submit" class="px-2 py-1 bg-red-50 text-red-600 text-label font-bold rounded-lg hover:bg-red-100 transition cursor-pointer">🗑️</button>
                                </form>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                                <div><span class="font-bold text-slate-400 uppercase text-label">What</span><p class="text-slate-600 mt-0.5"><?= htmlspecialchars($ref['what_done']) ?></p></div>
                                <div><span class="font-bold text-slate-400 uppercase text-label">How</span><p class="text-slate-600 mt-0.5"><?= htmlspecialchars($ref['how_done'] ?: '—') ?></p></div>
                                <div><span class="font-bold text-slate-400 uppercase text-label">Why</span><p class="text-slate-600 mt-0.5"><?= htmlspecialchars($ref['why_done'] ?: '—') ?></p></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- ════════════════════════════════════════════════════ -->
                <!-- TAB: EVALUATIONS -->
                <!-- ════════════════════════════════════════════════════ -->
                <?php elseif ($tab === 'evals'): ?>
                <!-- Add Evaluation Form -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-100">
                        <h2 class="text-sm font-black text-slate-800 uppercase tracking-wider flex items-center gap-2">
                            <span class="p-1 bg-amber-50 text-amber-600 rounded">⭐</span> Add Evaluation
                        </h2>
                    </div>
                    <form method="POST" class="p-5 space-y-4">
                        <input type="hidden" name="add_past_eval" value="1">
                        <input type="hidden" name="e_student_id" value="<?= $selected_student_id ?>">
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-500 mb-1">📅 Week Number *</label>
                                <input type="number" name="e_week" min="1" max="52" required placeholder="1" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-mono text-slate-800 focus:outline-none focus:border-indigo-500 transition">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 mb-1">🏆 Grade</label>
                                <select name="e_grade" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-indigo-500 transition">
                                    <option value="excellent">Excellent</option>
                                    <option value="good" selected>Good</option>
                                    <option value="average">Average</option>
                                    <option value="needs_improvement">Needs Improvement</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 mb-1">📋 Status</label>
                                <select name="e_status" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-indigo-500 transition">
                                    <option value="approved_by_instructor">Approved (Instructor)</option>
                                    <option value="approved_by_supervisor">Approved (Supervisor)</option>
                                    <option value="pending">Pending</option>
                                    <option value="rejected">Rejected</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 mb-1">💬 Comment</label>
                                <input type="text" name="e_comment" placeholder="Brief comment…" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-indigo-500 transition">
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" class="px-5 py-2 bg-amber-500 hover:bg-amber-600 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer">💾 Save Evaluation</button>
                        </div>
                    </form>
                </div>

                <!-- Existing Evaluations -->
                <?php if (!empty($student_evals)): ?>
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-100">
                        <h2 class="text-sm font-black text-slate-800 uppercase tracking-wider">⭐ Existing Evaluations (<?= count($student_evals) ?>)</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead>
                                <tr class="bg-slate-50/80 text-slate-500 font-semibold uppercase tracking-wider">
                                    <th class="px-3 py-2.5 text-left">Week</th>
                                    <th class="px-3 py-2.5 text-left">Grade</th>
                                    <th class="px-3 py-2.5 text-left">Status</th>
                                    <th class="px-3 py-2.5 text-left">Comment</th>
                                    <th class="px-3 py-2.5 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($student_evals as $ev): ?>
                                <?php
                                    $gmap = [
                                        'excellent' => ['Excellent', 'text-emerald-600', 'bg-emerald-50'],
                                        'good' => ['Good', 'text-blue-600', 'bg-blue-50'],
                                        'average' => ['Average', 'text-amber-600', 'bg-amber-50'],
                                        'needs_improvement' => ['Needs Improvement', 'text-red-600', 'bg-red-50'],
                                    ];
                                    $gs = $gmap[$ev['grade']] ?? ['—', 'text-slate-400', 'bg-slate-50'];
                                    $smap = [
                                        'approved_by_instructor' => ['Approved', 'text-emerald-600', 'bg-emerald-50'],
                                        'approved_by_supervisor' => ['Approved', 'text-emerald-600', 'bg-emerald-50'],
                                        'pending' => ['Pending', 'text-amber-600', 'bg-amber-50'],
                                        'rejected' => ['Rejected', 'text-red-600', 'bg-red-50'],
                                    ];
                                    $ss = $smap[$ev['report_status']] ?? ['—', 'text-slate-400', 'bg-slate-50'];
                                ?>
                                <tr class="hover:bg-slate-50/50 transition">
                                    <td class="px-3 py-2 font-bold text-indigo-600">Week <?= $ev['week_number'] ?></td>
                                    <td class="px-3 py-2"><span class="text-label font-bold <?= $gs[1] ?> <?= $gs[2] ?> px-2 py-0.5 rounded"><?= $gs[0] ?></span></td>
                                    <td class="px-3 py-2"><span class="text-label font-bold <?= $ss[1] ?> <?= $ss[2] ?> px-2 py-0.5 rounded"><?= $ss[0] ?></span></td>
                                    <td class="px-3 py-2 text-slate-600 max-w-[200px] truncate" title="<?= htmlspecialchars($ev['comment'] ?? '') ?>"><?= htmlspecialchars($ev['comment'] ?: '—') ?></td>
                                    <td class="px-3 py-2 text-right">
                                        <form method="POST" onsubmit="return confirm('Delete this evaluation?')" class="inline">
                                            <input type="hidden" name="delete_past_eval" value="1">
                                            <input type="hidden" name="eval_id" value="<?= $ev['id'] ?>">
                                            <input type="hidden" name="student_id" value="<?= $selected_student_id ?>">
                                            <button type="submit" class="px-2 py-1 bg-red-50 text-red-600 text-label font-bold rounded-lg hover:bg-red-100 transition cursor-pointer">🗑️</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <?php endif; ?>
                <?php else: ?>
                <?php if ($selected_student_id > 0): ?>
                <div class="bg-amber-50 border border-amber-200 text-amber-700 text-sm font-semibold px-4 py-3 rounded-xl flex items-center gap-2">
                    <span>⚠️</span> Student not found or not archived.
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <?php else: ?>
                <!-- Empty state for tabs without student selected -->
                <?php if ($tab === 'logs' || $tab === 'reflections' || $tab === 'evals'): ?>
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-8 text-center">
                    <div class="w-16 h-16 rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center text-3xl mx-auto mb-4">📋</div>
                    <p class="text-sm font-semibold text-slate-700 mb-1">Select a student above</p>
                    <p class="text-xs text-slate-400 max-w-sm mx-auto">Choose an archived student to add logs, reflections, or evaluations.</p>
                </div>
                <?php endif; ?>
                <?php endif; ?>

            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.fp-date').forEach(function(el) {
        flatpickr(el, {
            dateFormat: 'Y-m-d',
            disableMobile: true,
            weekStart: 0
        });
    });
});
</script>

</body>
</html>
