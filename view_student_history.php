<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/ui_helpers.php';

$uid = (int) ($_GET['uid'] ?? 0);
if ($uid <= 0) {
    $role = $_SESSION['role'] ?? '';
    if ($role === 'admin') header('Location: admin/admin-dashboard.php');
    elseif ($role === 'supervisor') header('Location: supervisor/supervisor-dashboard.php');
    else header('Location: dashboard.php');
    exit;
}

// ── Fetch Student Profile ────────────────────────────────────────
$stu = $pdo->prepare("
    SELECT u.id, u.username, u.email, u.academic_year, u.created_at,
           sp.full_name, sp.student_roll, sp.major, sp.company_name,
           sp.job_role, sp.instructor_name, sp.internship_start_date,
           sup_u.username AS supervisor_name
    FROM users u
    LEFT JOIN student_profiles sp ON sp.user_id = u.id
    LEFT JOIN student_profiles sp2 ON sp2.user_id = u.id
    LEFT JOIN users sup_u ON sup_u.id = sp2.supervisor_id
    WHERE u.id = ? AND u.role = 'student'
");
$stu->execute([$uid]);
$student = $stu->fetch();

if (!$student) {
    $role = $_SESSION['role'] ?? '';
    if ($role === 'admin') header('Location: admin/admin-dashboard.php');
    elseif ($role === 'supervisor') header('Location: supervisor/supervisor-dashboard.php');
    else header('Location: dashboard.php');
    exit;
}

$student_name = $student['full_name'] ?: $student['username'];

// ── Compute Week Ranges ──────────────────────────────────────────
$all_dates = $pdo->prepare("SELECT DISTINCT log_date FROM daily_logs WHERE internship_id = ? ORDER BY log_date ASC");
$all_dates->execute([$uid]);
$log_dates = $all_dates->fetchAll(PDO::FETCH_COLUMN);

$weeks = [];
if (!empty($log_dates)) {
    $first = new DateTime($log_dates[0]);
    $last  = new DateTime(end($log_dates));
    $num   = 1;
    $s     = clone $first;
    while ($s <= $last) {
        $e = (clone $s)->modify('+6 days');
        $weeks[$num] = ['start' => $s->format('Y-m-d'), 'end' => $e->format('Y-m-d')];
        $s->modify('+7 days');
        $num++;
    }
}

// ── Fetch All Logs, Reflections, Evaluations ─────────────────────
$all_logs = $pdo->prepare("SELECT * FROM daily_logs WHERE internship_id = ? ORDER BY log_date ASC");
$all_logs->execute([$uid]);
$all_logs = $all_logs->fetchAll();

$all_refs = $pdo->prepare("SELECT * FROM weekly_reflections WHERE internship_id = ? ORDER BY week_number ASC");
$all_refs->execute([$uid]);
$all_refs = $all_refs->fetchAll();

$all_evals = $pdo->prepare("SELECT * FROM report_evaluations WHERE student_id = ? ORDER BY week_number ASC");
$all_evals->execute([$uid]);
$all_evals = $all_evals->fetchAll();
$eval_by_week = [];
foreach ($all_evals as $ev) {
    $eval_by_week[$ev['week_number']] = $ev;
}

// Group logs by week
$logs_by_week = [];
foreach ($all_logs as $log) {
    $log_date = new DateTime($log['log_date']);
    foreach ($weeks as $wn => $wr) {
        $ws = new DateTime($wr['start']);
        $we = new DateTime($wr['end']);
        if ($log_date >= $ws && $log_date <= $we) {
            $logs_by_week[$wn][] = $log;
            break;
        }
    }
}

$refs_by_week = [];
foreach ($all_refs as $ref) {
    $refs_by_week[$ref['week_number']] = $ref;
}

// ── Overall Stats ────────────────────────────────────────────────
$present_q = $pdo->prepare("SELECT COUNT(*) FROM daily_logs WHERE internship_id = ? AND attendance_status = 'present'");
$present_q->execute([$uid]);
$total_present = (int) $present_q->fetchColumn();

$absent_q = $pdo->prepare("SELECT COUNT(*) FROM daily_logs WHERE internship_id = ? AND attendance_status IN ('absent','leave')");
$absent_q->execute([$uid]);
$total_absent = (int) $absent_q->fetchColumn();

$total_logs = count($all_logs);
$total_weeks = count($weeks);

// Back link
$back_url = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'admin/admin-dashboard.php?tab=history';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($student_name) ?> – History</title>
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
                    'body': '1rem',
                },
                }
            }
        }
    </script>
</head>
<body class="bg-slate-50 font-sans antialiased p-6">

<div class="max-w-5xl mx-auto space-y-6">

    <!-- Back Link -->
    <a href="<?= htmlspecialchars($back_url) ?>" class="inline-flex items-center gap-1.5 text-sm font-bold text-slate-500 hover:text-slate-700 transition">
        ← Back to Dashboard
    </a>

    <!-- ════ HEADER ════ -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
        <div class="flex items-start justify-between flex-wrap gap-4">
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center text-lg font-bold shrink-0">
                    <?= strtoupper($student_name[0]) ?>
                </div>
                <div>
                    <h1 class="text-sm font-black text-slate-800"><?= htmlspecialchars($student_name) ?></h1>
                    <p class="text-sm text-slate-400 font-mono mt-0.5">Roll: <?= htmlspecialchars($student['student_roll'] ?: '—') ?> · <?= htmlspecialchars($student['email']) ?></p>
                    <div class="flex items-center gap-2 mt-1.5 flex-wrap">
                        <?php if ($student['company_name']): ?>
                            <span class="text-sm font-bold text-blue-600 bg-blue-50 px-2 py-0.5 rounded"><?= htmlspecialchars($student['company_name']) ?></span>
                        <?php endif; ?>
                        <?php if ($student['academic_year']): ?>
                            <span class="text-sm font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded font-mono"><?= htmlspecialchars($student['academic_year']) ?></span>
                        <?php endif; ?>
                        <?php if ($student['supervisor_name']): ?>
                            <span class="text-sm font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded">Sup: <?= htmlspecialchars($student['supervisor_name']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="text-right">
                <p class="text-sm text-slate-400">Member since <?= (new DateTime($student['created_at']))->format('d M Y') ?></p>
            </div>
        </div>
    </div>

    <!-- ════ STATS CARDS ════ -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 text-center">
            <p class="text-2xl font-black text-slate-800"><?= $total_logs ?></p>
            <p class="text-sm font-bold text-slate-400 uppercase tracking-wider">Total Logs</p>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 text-center">
            <p class="text-2xl font-black text-emerald-600"><?= $total_present ?></p>
            <p class="text-sm font-bold text-slate-400 uppercase tracking-wider">Present Days</p>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 text-center">
            <p class="text-2xl font-black text-red-500"><?= $total_absent ?></p>
            <p class="text-sm font-bold text-slate-400 uppercase tracking-wider">Absent Days</p>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 text-center">
            <p class="text-2xl font-black text-indigo-600"><?= $total_weeks ?></p>
            <p class="text-sm font-bold text-slate-400 uppercase tracking-wider">Weeks Logged</p>
        </div>
    </div>

    <!-- ════ WEEKLY HISTORY ════ -->
    <?php if (!empty($weeks)): ?>
    <?php foreach ($weeks as $wn => $wr): ?>
    <?php
        $week_logs = $logs_by_week[$wn] ?? [];
        $week_ref  = $refs_by_week[$wn] ?? null;
        $week_eval = $eval_by_week[$wn] ?? null;
        $week_present = 0;
        $week_absent = 0;
        foreach ($week_logs as $wl) {
            if ($wl['attendance_status'] === 'present') $week_present++;
            else $week_absent++;
        }
    ?>
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <!-- Week Header -->
        <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between flex-wrap gap-2">
            <div class="flex items-center gap-3">
                <h2 class="text-xs font-black text-slate-700 uppercase tracking-wider">
                    📅 Week <?= $wn ?>
                </h2>
                <span class="text-sm text-slate-400">
                    <?= (new DateTime($wr['start']))->format('d M') ?> – <?= (new DateTime($wr['end']))->format('d M Y') ?>
                </span>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-sm font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded">✅ <?= $week_present ?></span>
                <span class="text-sm font-bold text-red-600 bg-red-50 px-2 py-0.5 rounded">❌ <?= $week_absent ?></span>
                <?php if ($week_eval): ?>
                    <?php
                    $gmap = [
                        'excellent'         => ['Excellent',         'text-emerald-600', 'bg-emerald-50'],
                        'good'              => ['Good',              'text-blue-600',    'bg-blue-50'],
                        'average'           => ['Average',           'text-amber-600',   'bg-amber-50'],
                        'needs_improvement' => ['Needs Improvement', 'text-red-600',     'bg-red-50'],
                    ];
                    $gs = $gmap[$week_eval['grade']] ?? ['—', 'text-slate-400', 'bg-slate-50'];
                    ?>
                    <span class="text-sm font-bold <?= $gs[1] ?> <?= $gs[2] ?> px-2 py-0.5 rounded"><?= $gs[0] ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="p-5 space-y-5">
            <!-- Daily Logs -->
            <?php if (!empty($week_logs)): ?>
            <div>
                <h3 class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-2">📝 Daily Logs</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="bg-slate-50 text-slate-500 font-bold uppercase tracking-wider text-sm">
                                <th class="px-3 py-2 text-left">Date</th>
                                <th class="px-3 py-2 text-left">Status</th>
                                <th class="px-3 py-2 text-left">Task</th>
                                <th class="px-3 py-2 text-left">Details</th>
                                <th class="px-3 py-2 text-left">Tools</th>
                                <th class="px-3 py-2 text-left">Duration</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($week_logs as $wl): ?>
                            <tr class="hover:bg-slate-50 transition">
                                <td class="px-3 py-2 font-medium text-slate-700 whitespace-nowrap">
                                    <?= (new DateTime($wl['log_date']))->format('D, d M') ?>
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    <?php if ($wl['attendance_status'] === 'present'): ?>
                                        <span class="text-sm font-bold text-emerald-600 bg-emerald-50 px-1.5 py-0.5 rounded">✅ Present</span>
                                    <?php else: ?>
                                        <span class="text-sm font-bold text-red-600 bg-red-50 px-1.5 py-0.5 rounded">❌ Absent</span>
                                        <?php if (!empty($wl['reason_for_absence'])): ?>
                                            <span class="text-sm text-slate-400 block mt-0.5" title="<?= htmlspecialchars($wl['reason_for_absence']) ?>">Reason noted</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <?php $is_absent = ($wl['attendance_status'] ?? 'present') === 'absent'; ?>
                                <td class="px-3 py-2 text-slate-600 max-w-[140px] truncate" title="<?= $is_absent ? '' : htmlspecialchars($wl['task_title'] ?? '') ?>"><?= $is_absent ? '-' : htmlspecialchars($wl['task_title'] ?: '-') ?></td>
                                <td class="px-3 py-2 text-slate-600 max-w-[160px] truncate" title="<?= $is_absent ? '' : htmlspecialchars($wl['task_detail'] ?? '') ?>"><?= $is_absent ? '-' : htmlspecialchars($wl['task_detail'] ?: '-') ?></td>
                                <td class="px-3 py-2 text-slate-600"><?= $is_absent ? '-' : htmlspecialchars($wl['tools_used'] ?: '-') ?></td>
                                <td class="px-3 py-2 font-mono text-blue-600 font-bold whitespace-nowrap"><?= htmlspecialchars($wl['calculated_duration']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php else: ?>
            <p class="text-sm text-slate-400 text-center py-2">No logs submitted for this week.</p>
            <?php endif; ?>

            <!-- Weekly Reflection -->
            <?php if ($week_ref): ?>
            <div class="border-t border-slate-100 pt-4">
                <h3 class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-2">📊 Weekly Reflection</h3>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div class="bg-slate-50 rounded-xl p-3">
                        <span class="text-sm font-bold text-slate-400 uppercase tracking-wider block mb-0.5">What was done?</span>
                        <p class="text-sm text-slate-600 leading-relaxed"><?= nl2br(htmlspecialchars($week_ref['what_done'] ?? '')) ?></p>
                    </div>
                    <div class="bg-slate-50 rounded-xl p-3">
                        <span class="text-sm font-bold text-slate-400 uppercase tracking-wider block mb-0.5">How was it done?</span>
                        <p class="text-sm text-slate-600 leading-relaxed"><?= nl2br(htmlspecialchars($week_ref['how_done'] ?? '')) ?></p>
                    </div>
                    <div class="bg-slate-50 rounded-xl p-3">
                        <span class="text-sm font-bold text-slate-400 uppercase tracking-wider block mb-0.5">Why was it done?</span>
                        <p class="text-sm text-slate-600 leading-relaxed"><?= nl2br(htmlspecialchars($week_ref['why_done'] ?? '')) ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Evaluation -->
            <?php if ($week_eval && $week_eval['report_status'] !== 'pending'): ?>
            <div class="border-t border-slate-100 pt-4">
                <h3 class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-2">⭐ Instructor Evaluation</h3>
                <div class="bg-slate-50 rounded-xl p-3 flex items-start gap-4">
                    <div class="flex-1">
                        <div class="flex items-center gap-2 mb-1">
                            <?php
                            $gs = grade_badge_styles($week_eval['grade'] ?? '');
                            $sb = report_status_badge($week_eval['report_status'] ?? 'pending');
                            ?>
                            <span class="text-sm font-bold <?= $gs['text'] ?> <?= $gs['bg'] ?> px-2 py-0.5 rounded"><?= $gs['label'] ?></span>
                            <span class="text-sm font-bold <?= $sb['classes'] ?> px-2 py-0.5 rounded"><?= $sb['label'] ?></span>
                        </div>
                        <?php if ($week_eval['comment']): ?>
                            <p class="text-sm text-slate-600 leading-relaxed mt-1"><?= nl2br(htmlspecialchars($week_eval['comment'])) ?></p>
                        <?php endif; ?>
                        <?php if ($week_eval['instructor_comments']): ?>
                            <div class="bg-red-50 border border-red-100 rounded-lg p-2 mt-2">
                                <p class="text-sm font-bold text-red-500 mb-0.5">Rejection Reason:</p>
                                <p class="text-sm text-red-600"><?= nl2br(htmlspecialchars($week_eval['instructor_comments'])) ?></p>
                            </div>
                        <?php endif; ?>
                        <?php if ($week_eval['signature_type'] === 'typed' && $week_eval['signature_value']): ?>
                            <p style="font-family:'Great Vibes',cursive; font-size:20px; color:#1e293b;" class="mt-2"><?= htmlspecialchars($week_eval['signature_value']) ?></p>
                        <?php elseif ($week_eval['signature_type'] === 'uploaded' && $week_eval['signature_value']): ?>
                            <img src="uploads/signatures/<?= htmlspecialchars($week_eval['signature_value']) ?>" alt="Signature" class="h-10 mt-2">
                        <?php endif; ?>
                    </div>
                    <span class="text-sm text-slate-300 shrink-0"><?= (new DateTime($week_eval['evaluated_at']))->format('d M Y') ?></span>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-8 text-center">
        <p class="text-xs text-slate-400">No weekly data recorded for this student yet.</p>
    </div>
    <?php endif; ?>

    <div class="text-center text-sm text-slate-300 py-2">Powered by InternReport</div>
</div>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Great+Vibes&display=swap" rel="stylesheet">

</body>
</html>
