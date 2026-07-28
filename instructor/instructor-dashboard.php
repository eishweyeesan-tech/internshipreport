<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth.php';

if ($_SESSION['role'] !== 'instructor') {
    header('Location: ../dashboard.php');
    exit;
}

$inst_id   = $_SESSION['user_id'];
$inst_name = $_SESSION['username'];

// Fetch instructor email
$inst_email_q = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$inst_email_q->execute([$inst_id]);
$inst_email = $inst_email_q->fetchColumn();

// ══════════════════════════════════════════════════════════════════════
// FETCH ASSIGNED STUDENTS
// Students where instructor_id matches OR instructor_email matches
// ══════════════════════════════════════════════════════════════════════
$stu_stmt = $pdo->prepare("
    SELECT u.id AS uid, u.username, u.email, u.academic_year,
           sp.full_name, sp.student_roll, sp.major, sp.company_name,
           sp.job_role, sp.supervisor_id,
           sup_u.username AS supervisor_name,
           sp.internship_start_date, sp.internship_end_date
    FROM users u
    JOIN student_profiles sp ON sp.user_id = u.id
    LEFT JOIN users sup_u ON sup_u.id = sp.supervisor_id
    WHERE u.role = 'student'
      AND (sp.instructor_id = ? OR (sp.instructor_email != '' AND sp.instructor_email = ?))
    ORDER BY sp.full_name ASC
");
$stu_stmt->execute([$inst_id, $inst_email]);
$all_students = $stu_stmt->fetchAll();

// ══════════════════════════════════════════════════════════════════════
// COMPUTE PER-STUDENT STATUS FOR CURRENT WEEK
// ══════════════════════════════════════════════════════════════════════
$today = new DateTime();
$today_str = $today->format('Y-m-d');
$max_week = 12;

$student_status = [];
foreach ($all_students as $stu) {
    $uid = $stu['uid'];
    $dw = 1;
    $not_started = false;

    if ($stu['internship_start_date']) {
        $start_date = new DateTime($stu['internship_start_date']);
        $end_date = $stu['internship_end_date'] ? new DateTime($stu['internship_end_date']) : null;

        if ($today < $start_date) {
            $dw = 1;
            $not_started = true;
        } elseif ($end_date && $today > $end_date) {
            $dw = $max_week;
        } else {
            $days_elapsed = (int) $today->diff($start_date)->days;
            $dw = (int) floor($days_elapsed / 7) + 1;
            $dw = max(1, min($dw, $max_week));
        }
    } else {
        $not_started = true;
    }

    // Compute week date range
    if ($stu['internship_start_date']) {
        $stu_start = new DateTime($stu['internship_start_date']);
        $stu_week_start = (clone $stu_start)->modify('+' . (($dw - 1) * 7) . ' days');
        $stu_week_end = (clone $stu_week_start)->modify('+6 days');
        $sws = $stu_week_start->format('Y-m-d');
        $swe = $stu_week_end->format('Y-m-d');
    } else {
        $sws = $today->modify('monday this week')->format('Y-m-d');
        $swe = $today->modify('sunday this week')->format('Y-m-d');
    }

    // Daily logs count for current week
    $log_q = $pdo->prepare("SELECT COUNT(*) FROM daily_logs WHERE internship_id = ? AND log_date BETWEEN ? AND ?");
    $log_q->execute([$uid, $sws, $swe]);
    $log_count = (int) $log_q->fetchColumn();

    // Instructor evaluation status
    $eval_q = $pdo->prepare("SELECT report_status, grade, evaluated_at FROM report_evaluations WHERE student_id = ? AND week_number = ?");
    $eval_q->execute([$uid, $dw]);
    $eval = $eval_q->fetch();
    $eval_status = $eval ? $eval['report_status'] : 'pending';

    // Magic link available?
    $link_q = $pdo->prepare("SELECT token, expires_at FROM magic_links WHERE internship_id = ? AND week_number = ? AND expires_at > NOW() LIMIT 1");
    $link_q->execute([$uid, $dw]);
    $magic_link = $link_q->fetch();

    // Total logs count
    $total_log_q = $pdo->prepare("SELECT COUNT(*) FROM daily_logs WHERE internship_id = ?");
    $total_log_q->execute([$uid]);
    $total_logs = (int) $total_log_q->fetchColumn();

    // Total graded weeks
    $graded_q = $pdo->prepare("SELECT COUNT(*) FROM report_evaluations WHERE student_id = ? AND report_status IN ('approved_by_instructor', 'approved_by_supervisor')");
    $graded_q->execute([$uid]);
    $graded_weeks = (int) $graded_q->fetchColumn();

    $student_status[$uid] = [
        'current_week'  => $dw,
        'not_started'   => $not_started,
        'log_count'     => $log_count,
        'eval_status'   => $eval_status,
        'has_link'      => $magic_link !== false,
        'magic_token'   => $magic_link ? $magic_link['token'] : null,
        'total_logs'    => $total_logs,
        'graded_weeks'  => $graded_weeks,
    ];
}

// Count stats
$total_students = count($all_students);
$pending_count = 0;
$approved_count = 0;
$rejected_count = 0;
foreach ($student_status as $st) {
    if ($st['eval_status'] === 'approved_by_instructor' || $st['eval_status'] === 'approved_by_supervisor') {
        $approved_count++;
    } elseif ($st['eval_status'] === 'rejected') {
        $rejected_count++;
    } else {
        $pending_count++;
    }
}

// ══════════════════════════════════════════════════════════════════════
// HANDLE MAGIC LINK GENERATION FOR A STUDENT
// ══════════════════════════════════════════════════════════════════════
$generated_link = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_link'])) {
    $link_student_id = (int) ($_POST['student_id'] ?? 0);
    $link_week = (int) ($_POST['week_number'] ?? 0);

    if ($link_student_id > 0 && $link_week > 0) {
        $token = bin2hex(random_bytes(16));
        $expires_at = date('Y-m-d H:i:s', strtotime('+7 days'));

        $esc_token = $pdo->quote($token);
        $esc_exp   = $pdo->quote($expires_at);

        $pdo->prepare("INSERT INTO magic_links (internship_id, week_number, token, expires_at)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at)")
            ->execute([$link_student_id, $link_week, $token, $expires_at]);

        $generated_link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
            . "://$_SERVER[HTTP_HOST]" . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/')
            . '/instructor/view-report.php?token=' . $token;

        header('Location: instructor-dashboard.php?generated=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instructor Dashboard – InternReport</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        'inter': ['Inter', 'sans-serif'],
                    },
                }
            }
        }
    </script>
    <script>
    function toggleProfileDropdown(e) {
        e.stopPropagation();
        document.getElementById('profile-dropdown-menu').classList.toggle('hidden');
    }
    document.addEventListener('click', function(e) {
        var dd = document.getElementById('profile-dropdown-menu');
        var btn = document.getElementById('profile-avatar-btn');
        if (dd && !dd.contains(e.target) && !btn.contains(e.target)) {
            dd.classList.add('hidden');
        }
    });
    function copyLink(inputId) {
        var input = document.getElementById(inputId);
        if (!input || !input.value) return;
        navigator.clipboard.writeText(input.value).then(function () {
            var btn = input.nextElementSibling;
            if (btn) { btn.textContent = '✓ Copied!'; setTimeout(function() { btn.textContent = '📋 Copy'; }, 2000); }
        });
    }
    </script>
</head>
<body class="bg-gradient-to-br from-slate-50 via-gray-50 to-slate-100 font-inter antialiased">

<div class="flex h-screen overflow-hidden">

    <!-- ─── SIDEBAR ─── -->
    <aside class="w-64 bg-white/80 backdrop-blur-xl border-r border-slate-200/60 flex flex-col shrink-0 shadow-xl shadow-slate-200/20">
        <div class="h-16 flex items-center px-6 border-b border-slate-100/80 bg-gradient-to-r from-amber-500/5 to-orange-500/5">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-amber-500 to-amber-600 flex items-center justify-center shadow-lg shadow-amber-500/30">
                    <span class="text-white text-sm">📋</span>
                </div>
                <div>
                    <span class="text-sm font-extrabold text-slate-800 tracking-tight">InternReport</span>
                    <span class="block text-sm font-bold text-amber-600 bg-amber-50 px-1.5 py-0.5 rounded mt-0.5">INSTRUCTOR</span>
                </div>
            </div>
        </div>
        <nav class="flex-1 py-5 px-3 space-y-1">
            <a href="instructor-dashboard.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-sm font-semibold bg-gradient-to-r from-amber-500 to-amber-600 text-white shadow-lg shadow-amber-500/30 transition-all duration-200">
                <span class="text-base">📊</span> Dashboard
            </a>
            <a href="../supervisor/supervisor-dashboard.php" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-sm font-medium text-slate-600 hover:bg-slate-50 hover:text-slate-800 transition-all duration-200">
                <span class="text-base">👩‍🏫</span> Supervisor View
            </a>
        </nav>
        <div class="p-3 border-t border-slate-100/80">
            <a href="../logout.php" class="flex items-center gap-3 px-4 py-2.5 text-sm font-semibold text-red-500 hover:bg-red-50 rounded-xl transition-all duration-200">
                <span class="text-base">🚪</span> Logout
            </a>
        </div>
    </aside>

    <!-- ─── MAIN ─── -->
    <div class="flex-1 flex flex-col overflow-hidden">

        <!-- Top Bar -->
        <header class="h-16 bg-white/80 backdrop-blur-xl border-b border-slate-200/60 flex items-center justify-between px-8 shrink-0 shadow-sm">
            <div class="flex items-center gap-4">
                <h1 class="text-base font-bold text-slate-800">Instructor Dashboard</h1>
            </div>
            <div class="flex items-center gap-5">
                <div class="flex items-center gap-2 px-3 py-1.5 bg-amber-50 border border-amber-200 rounded-full">
                    <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
                    <span class="text-xs font-bold text-amber-700"><?= $total_students ?> Student<?= $total_students !== 1 ? 's' : '' ?></span>
                </div>
                <div class="flex items-center gap-3 pl-5 border-l border-slate-200 relative">
                    <button id="profile-avatar-btn" onclick="toggleProfileDropdown(event)" class="relative focus:outline-none">
                        <?php if (!empty($_SESSION['profile_pic'])): ?>
                        <img src="../uploads/avatars/<?= htmlspecialchars($_SESSION['profile_pic']) ?>" alt="Avatar" class="w-10 h-10 rounded-full object-cover border-2 border-white shadow-lg shadow-amber-500/20">
                        <?php else: ?>
                        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-amber-500 to-orange-600 text-white flex items-center justify-center text-xs font-bold shadow-lg shadow-amber-500/20">
                            <?= strtoupper(substr($inst_name, 0, 1)) ?>
                        </div>
                        <?php endif; ?>
                    </button>
                    <div class="text-right">
                        <p class="text-xs font-bold text-slate-700"><?= htmlspecialchars($inst_name) ?></p>
                        <p class="text-sm text-slate-400">Company Instructor</p>
                    </div>
                    <div id="profile-dropdown-menu" class="hidden absolute right-0 top-full mt-2 z-50 bg-white border border-slate-200 rounded-xl shadow-xl w-48 py-2">
                        <div class="px-4 py-2.5 border-b border-slate-100">
                            <p class="text-sm font-bold text-slate-400">Signed in as</p>
                            <p class="text-xs font-semibold text-slate-700"><?= htmlspecialchars($inst_email) ?></p>
                        </div>
                        <div class="my-1 border-t border-slate-100"></div>
                        <a href="../logout.php" class="flex items-center gap-2.5 px-4 py-2.5 text-xs font-semibold text-red-500 hover:bg-red-50 transition">
                            <span>🚪</span> Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="flex-1 overflow-y-auto p-8">
            <div class="max-w-7xl mx-auto space-y-6">

                <?php if (isset($_GET['generated'])): ?>
                <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm font-semibold px-5 py-4 rounded-2xl flex items-center gap-3 shadow-sm">
                    <div class="w-8 h-8 rounded-xl bg-emerald-500 text-white flex items-center justify-center text-sm shadow-lg shadow-emerald-500/30">✅</div>
                    <span>Magic link generated successfully. Copy and share it with the instructor for review.</span>
                </div>
                <?php endif; ?>

                <!-- ═══ STATS CARDS ═══ -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-5">
                    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-5 hover:shadow-md transition-shadow duration-300">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-amber-500 to-amber-600 text-white flex items-center justify-center text-xl shadow-lg shadow-amber-500/30">👨‍🏫</div>
                            <div>
                                <p class="text-sm font-semibold text-slate-400 uppercase tracking-wider">My Students</p>
                                <p class="text-2xl font-black text-slate-800"><?= $total_students ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-5 hover:shadow-md transition-shadow duration-300">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-amber-500 to-orange-500 text-white flex items-center justify-center text-xl shadow-lg shadow-amber-500/30">⏳</div>
                            <div>
                                <p class="text-sm font-semibold text-slate-400 uppercase tracking-wider">Pending Review</p>
                                <p class="text-2xl font-black text-slate-800"><?= $pending_count ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-5 hover:shadow-md transition-shadow duration-300">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-emerald-500 to-emerald-600 text-white flex items-center justify-center text-xl shadow-lg shadow-emerald-500/30">✅</div>
                            <div>
                                <p class="text-sm font-semibold text-slate-400 uppercase tracking-wider">Approved</p>
                                <p class="text-2xl font-black text-slate-800"><?= $approved_count ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-5 hover:shadow-md transition-shadow duration-300">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-red-500 to-red-600 text-white flex items-center justify-center text-xl shadow-lg shadow-red-500/30">❌</div>
                            <div>
                                <p class="text-sm font-semibold text-slate-400 uppercase tracking-wider">Rejected</p>
                                <p class="text-2xl font-black text-slate-800"><?= $rejected_count ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ═══ STUDENT TABLE ═══ -->
                <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white">
                        <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                            <span class="w-8 h-8 rounded-lg bg-amber-50 text-amber-500 flex items-center justify-center text-sm">📋</span> Assigned Students
                        </h2>
                    </div>
                    <?php if (!empty($all_students)): ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-slate-50/80 text-slate-500 font-semibold uppercase tracking-wider text-sm">
                                    <th class="px-5 py-3 text-left">Student</th>
                                    <th class="px-5 py-3 text-left">Roll No</th>
                                    <th class="px-5 py-3 text-left">Job Role</th>
                                    <th class="px-5 py-3 text-left">Company</th>
                                    <th class="px-5 py-3 text-left">Supervisor</th>
                                    <th class="px-5 py-3 text-left">Week</th>
                                    <th class="px-5 py-3 text-left">Logs</th>
                                    <th class="px-5 py-3 text-left">Status</th>
                                    <th class="px-5 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($all_students as $stu):
                                    $uid = $stu['uid'];
                                    $st = $student_status[$uid];
                                ?>
                                <tr class="hover:bg-slate-50/50 transition-colors duration-150">
                                    <td class="px-5 py-4">
                                        <div class="flex items-center gap-3">
                                            <div class="w-9 h-9 rounded-full bg-gradient-to-br from-amber-400 to-orange-500 text-white flex items-center justify-center text-xs font-bold shrink-0">
                                                <?= strtoupper(($stu['full_name'] ?: $stu['username'])[0]) ?>
                                            </div>
                                            <div>
                                                <p class="font-bold text-slate-700"><?= htmlspecialchars($stu['full_name'] ?: $stu['username']) ?></p>
                                                <p class="text-sm text-slate-400"><?= htmlspecialchars($stu['email']) ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 text-xs font-mono text-slate-600"><?= htmlspecialchars($stu['student_roll'] ?: '—') ?></td>
                                    <td class="px-5 py-4 text-xs text-slate-600"><?= htmlspecialchars($stu['job_role'] ?: '—') ?></td>
                                    <td class="px-5 py-4 text-xs text-slate-600"><?= htmlspecialchars($stu['company_name'] ?: '—') ?></td>
                                    <td class="px-5 py-4 text-xs text-slate-600"><?= htmlspecialchars($stu['supervisor_name'] ?: '—') ?></td>
                                    <td class="px-5 py-4">
                                        <span class="text-xs font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded font-mono">W<?= $st['current_week'] ?></span>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="text-xs font-bold <?= $st['log_count'] >= 5 ? 'text-emerald-600' : 'text-amber-600' ?>"><?= $st['log_count'] ?>/5</span>
                                    </td>
                                    <td class="px-5 py-4">
                                        <?php if ($st['eval_status'] === 'approved_by_instructor' || $st['eval_status'] === 'approved_by_supervisor'): ?>
                                        <span class="inline-flex items-center gap-1.5 text-sm font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2.5 py-1 rounded-lg">
                                            <span class="w-2 h-2 rounded-full bg-emerald-500"></span> ✅ Approved
                                        </span>
                                        <?php elseif ($st['eval_status'] === 'rejected'): ?>
                                        <span class="inline-flex items-center gap-1.5 text-sm font-bold text-red-700 bg-red-50 border border-red-200 px-2.5 py-1 rounded-lg">
                                            <span class="w-2 h-2 rounded-full bg-red-500"></span> ❌ Rejected
                                        </span>
                                        <?php else: ?>
                                        <span class="inline-flex items-center gap-1.5 text-sm font-bold text-amber-700 bg-amber-50 border border-amber-200 px-2.5 py-1 rounded-lg">
                                            <span class="w-2 h-2 rounded-full bg-amber-500"></span> ⏳ Pending
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4 whitespace-nowrap text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <?php if ($st['magic_token']): ?>
                                            <a href="view-report.php?token=<?= htmlspecialchars($st['magic_token']) ?>" class="px-2.5 py-1 text-sm font-bold text-amber-600 bg-amber-50 border border-amber-200 rounded-lg hover:bg-amber-100 transition">📋 Review</a>
                                            <?php else: ?>
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="student_id" value="<?= $uid ?>">
                                                <input type="hidden" name="week_number" value="<?= $st['current_week'] ?>">
                                                <button type="submit" name="generate_link" class="px-2.5 py-1 text-sm font-bold text-indigo-600 bg-indigo-50 border border-indigo-200 rounded-lg hover:bg-indigo-100 transition cursor-pointer">🔗 Get Link</button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="p-12 text-center">
                        <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center text-3xl mx-auto mb-4">📭</div>
                        <p class="text-sm text-slate-500 font-medium">No students assigned to you yet.</p>
                        <p class="text-xs text-slate-400 mt-1">Students will appear here once their profile has your email as the instructor.</p>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- ═══ WORKFLOW INFO ═══ -->
                <div class="bg-gradient-to-r from-amber-600 via-orange-500 to-amber-600 rounded-2xl p-6 text-white shadow-xl shadow-amber-500/20">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-2xl bg-white/20 backdrop-blur-sm flex items-center justify-center text-xl">💡</div>
                        <div>
                            <p class="text-sm font-bold">How the Review Process Works</p>
                            <p class="text-xs text-amber-100 mt-1 leading-relaxed">
                                1. Students submit daily logs and weekly reflections → 2. Students sign & generate a magic link →
                                <strong>3. You review and approve/reject (you are here!)</strong> → 4. University supervisor assigns final grade.
                            </p>
                        </div>
                    </div>
                </div>

            </div>
        </main>
    </div>
</div>

</body>
</html>
