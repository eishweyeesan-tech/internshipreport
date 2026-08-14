<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/init_year.php';
require_once __DIR__ . '/../auth.php';

if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../dashboard.php');
    exit;
}

$admin_name = $_SESSION['username'] ?? 'Admin';
$admin_id   = (int) ($_SESSION['user_id'] ?? 0);
$db         = $mysqli ?? $conn;

// Notifications
$unread_notif_q = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unread_notif_q->bind_param("i", $admin_id);
$unread_notif_q->execute();
$res = $unread_notif_q->get_result();
$row = $res ? $res->fetch_row() : null;
$unread_notif_count = (int) ($row[0] ?? 0);

$recent_notifs_q = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$recent_notifs_q->bind_param("i", $admin_id);
$recent_notifs_q->execute();
$res = $recent_notifs_q->get_result();
$recent_notifications = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

// Fetch all academic years with student counts
$res = $db->query("
    SELECT ay.*,
           (SELECT COUNT(*) FROM users u WHERE u.academic_year_id = ay.id AND u.role = 'student') AS student_count,
           (SELECT COUNT(*) FROM users u WHERE u.academic_year_id = ay.id AND u.role = 'supervisor') AS supervisor_count
    FROM academic_years ay
    ORDER BY ay.start_date DESC
");
$years = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

$current_active = null;
foreach ($years as $y) {
    if ($y['status'] === 'ACTIVE') { $current_active = $y; break; }
}

$selected_year = $current_academic_year ?? null;
$selected_year_history = [];
if (!empty($selected_year['id'])) {
    $selected_year_id = (int) $selected_year['id'];

    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE role = 'student' AND academic_year_id = ?");
    $stmt->bind_param("i", $selected_year_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_row() : null;
    $selected_year_history['students'] = (int) ($row[0] ?? 0);

    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE role = 'supervisor' AND academic_year_id = ?");
    $stmt->bind_param("i", $selected_year_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_row() : null;
    $selected_year_history['supervisors'] = (int) ($row[0] ?? 0);

    $stmt = $db->prepare("SELECT COUNT(DISTINCT sp.company_name) FROM student_profiles sp JOIN users u ON u.id = sp.user_id WHERE u.role = 'student' AND u.academic_year_id = ? AND sp.company_name IS NOT NULL AND sp.company_name != ''");
    $stmt->bind_param("i", $selected_year_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_row() : null;
    $selected_year_history['companies'] = (int) ($row[0] ?? 0);

    $stmt = $db->prepare("SELECT COUNT(*) FROM daily_logs dl JOIN student_profiles sp ON sp.id = dl.internship_id JOIN users u ON u.id = sp.user_id WHERE u.role = 'student' AND u.academic_year_id = ?");
    $stmt->bind_param("i", $selected_year_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_row() : null;
    $selected_year_history['logs'] = (int) ($row[0] ?? 0);

    $stmt = $db->prepare("SELECT u.id, u.username, sp.full_name, sp.student_roll, sp.company_name FROM users u LEFT JOIN student_profiles sp ON sp.user_id = u.id WHERE u.role = 'student' AND u.academic_year_id = ? ORDER BY sp.full_name ASC LIMIT 8");
    $stmt->bind_param("i", $selected_year_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $selected_year_history['students_list'] = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}
?>
<!DOCTYPE html>
<html lang="en">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Academic Years – Admin – InternReport</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
    (function() {
        var theme = localStorage.getItem('theme');
        if (theme === 'dark') document.documentElement.classList.add('dark');
    })();
    tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                fontFamily: { 'inter': ['Inter', 'sans-serif'] },
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
<body class="bg-slate-50 font-sans antialiased">

<div class="flex h-screen overflow-hidden">

    <?php $activePage = 'academic-years'; ?>
    <?php require_once __DIR__ . '/../includes/admin-sidebar.php'; ?>

    <!-- ─── MAIN ─── -->
    <div class="flex-1 flex flex-col overflow-hidden">

        <?php $pageTitle = 'Academic Year Management'; require_once __DIR__ . '/../includes/topbar.php'; ?>

        <main class="flex-1 overflow-y-auto p-6" style="scrollbar-gutter: stable;">

            <!-- Success/Error Toast -->
            <div id="toast" class="hidden fixed top-6 right-6 z-[100] max-w-sm"></div>

            <div class="max-w-6xl mx-auto space-y-6">

                <!-- Header Row -->
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-black text-slate-800">Academic Years</h2>
                        <p class="text-sm text-slate-400 mt-0.5">Manage year sessions, transition active year, and create upcoming years.</p>
                    </div>
                    <button
                        onclick="document.getElementById('addYearModal').classList.remove('hidden')"
                        class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer flex items-center gap-2"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                        Add New Year
                    </button>
                </div>

                <!-- Active Year Banner -->
                <?php if ($current_active): ?>
                <div class="bg-gradient-to-r from-emerald-500 to-teal-600 rounded-2xl p-5 text-white shadow-lg shadow-emerald-500/20">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-white/20 backdrop-blur-sm flex items-center justify-center text-xl border border-white/30">
                                🟢
                            </div>
                            <div>
                                <p class="text-xs font-bold uppercase tracking-wider text-emerald-100">Currently Active Year</p>
                                <p class="text-2xl font-black mt-0.5"><?= htmlspecialchars($current_active['year_label']) ?></p>
                                <p class="text-xs text-emerald-100 mt-0.5">
                                    <?= (new DateTime($current_active['start_date']))->format('d M Y') ?> – <?= (new DateTime($current_active['end_date']))->format('d M Y') ?>
                                    · <?= $current_active['student_count'] ?> student<?= $current_active['student_count'] != 1 ? 's' : '' ?>
                                    · <?= $current_active['supervisor_count'] ?> supervisor<?= $current_active['supervisor_count'] != 1 ? 's' : '' ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 flex items-center gap-3">
                    <span class="text-xl">⚠️</span>
                    <div>
                        <p class="text-sm font-bold text-amber-700">No active academic year</p>
                        <p class="text-xs text-amber-500">Transition an UPCOMING year to ACTIVE to get started.</p>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($selected_year['id'])): ?>
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                        <div>
                            <p class="text-xs uppercase tracking-wider text-slate-400">Selected Academic Year</p>
                            <h3 class="text-xl font-black text-slate-800 mt-1"><?= htmlspecialchars($selected_year['year_label']) ?></h3>
                            <p class="text-sm text-slate-500 mt-1"><?= (new DateTime($selected_year['start_date']))->format('d M Y') ?> – <?= (new DateTime($selected_year['end_date']))->format('d M Y') ?></p>
                        </div>
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <div class="bg-slate-50 rounded-2xl p-4 text-center">
                                <p class="text-xs uppercase tracking-wider text-slate-400">Students</p>
                                <p class="text-2xl font-black text-slate-800 mt-2"><?= $selected_year_history['students'] ?? 0 ?></p>
                            </div>
                            <div class="bg-slate-50 rounded-2xl p-4 text-center">
                                <p class="text-xs uppercase tracking-wider text-slate-400">Supervisors</p>
                                <p class="text-2xl font-black text-slate-800 mt-2"><?= $selected_year_history['supervisors'] ?? 0 ?></p>
                            </div>
                            <div class="bg-slate-50 rounded-2xl p-4 text-center">
                                <p class="text-xs uppercase tracking-wider text-slate-400">Companies</p>
                                <p class="text-2xl font-black text-slate-800 mt-2"><?= $selected_year_history['companies'] ?? 0 ?></p>
                            </div>
                            <div class="bg-slate-50 rounded-2xl p-4 text-center">
                                <p class="text-xs uppercase tracking-wider text-slate-400">Daily logs</p>
                                <p class="text-2xl font-black text-slate-800 mt-2"><?= $selected_year_history['logs'] ?? 0 ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="mt-6">
                        <h4 class="text-sm font-bold text-slate-700 mb-3">Sample Students</h4>
                        <?php if (!empty($selected_year_history['students_list'])): ?>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <?php foreach ($selected_year_history['students_list'] as $student): ?>
                            <div class="rounded-2xl border border-slate-100 p-4 bg-slate-50">
                                <p class="font-semibold text-slate-800"><?= htmlspecialchars($student['full_name'] ?: $student['username']) ?></p>
                                <p class="text-xs text-slate-500 mt-1"><?= htmlspecialchars($student['student_roll'] ?? 'No roll') ?><?= !empty($student['company_name']) ? ' · ' . htmlspecialchars($student['company_name']) : '' ?></p>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <p class="text-sm text-slate-500">No students are linked to this year yet.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Years Table -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
                        <h3 class="text-sm font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
                            <span class="p-1 bg-slate-100 text-slate-600 rounded">📅</span> All Academic Years
                        </h3>
                        <span class="text-label font-bold text-slate-400"><?= count($years) ?> total</span>
                    </div>

                    <?php if (empty($years)): ?>
                    <div class="p-12 text-center">
                        <div class="w-16 h-16 rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center text-3xl mx-auto mb-4">📭</div>
                        <p class="text-sm font-semibold text-slate-700 mb-1">No academic years yet</p>
                        <p class="text-xs text-slate-400 max-w-sm mx-auto">Click "Add New Year" to create your first academic year session.</p>
                    </div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-slate-50 text-slate-500 font-bold uppercase tracking-wider text-xs">
                                    <th class="px-5 py-3 text-left">Year</th>
                                    <th class="px-5 py-3 text-left">Duration</th>
                                    <th class="px-5 py-3 text-center">Status</th>
                                    <th class="px-5 py-3 text-center">Current</th>
                                    <th class="px-5 py-3 text-center">Students</th>
                                    <th class="px-5 py-3 text-center">Supervisors</th>
                                    <th class="px-5 py-3 text-left">Created</th>
                                    <th class="px-5 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($years as $y): ?>
                                <?php
                                    $is_current = (int) $y['is_current'] === 1;
                                    $status_cfg = match($y['status']) {
                                        'ACTIVE'   => ['bg-emerald-100 text-emerald-700 border-emerald-200', '🟢 Active'],
                                        'UPCOMING' => ['bg-blue-100 text-blue-700 border-blue-200',       '🔵 Upcoming'],
                                        'ARCHIVED' => ['bg-slate-100 text-slate-500 border-slate-200',    '📦 Archived'],
                                        default    => ['bg-slate-100 text-slate-500 border-slate-200',    '—'],
                                    };
                                ?>
                                <tr class="hover:bg-slate-50/50 transition <?= $is_current ? 'bg-emerald-50/30' : '' ?>">
                                    <td class="px-5 py-4">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-xl <?= $is_current ? 'bg-emerald-100 text-emerald-600' : 'bg-slate-100 text-slate-500' ?> flex items-center justify-center text-sm font-bold shrink-0">
                                                📅
                                            </div>
                                            <div>
                                                <p class="font-bold text-slate-800"><?= htmlspecialchars($y['year_label']) ?></p>
                                                <p class="text-caption text-slate-400">ID: <?= $y['id'] ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 text-slate-600 whitespace-nowrap">
                                        <?= (new DateTime($y['start_date']))->format('d M Y') ?> – <?= (new DateTime($y['end_date']))->format('d M Y') ?>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <span class="inline-block text-micro font-bold px-2.5 py-1 rounded-full border <?= $status_cfg[0] ?>">
                                            <?= $status_cfg[1] ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <?php if ($is_current): ?>
                                        <span class="inline-flex items-center gap-1 text-micro font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-200">
                                            <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span> Current
                                        </span>
                                        <?php else: ?>
                                        <span class="text-slate-300 text-micro">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <span class="font-bold text-slate-700"><?= $y['student_count'] ?></span>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <span class="font-bold text-slate-700"><?= $y['supervisor_count'] ?></span>
                                    </td>
                                    <td class="px-5 py-4 text-slate-400 whitespace-nowrap text-xs">
                                        <?= (new DateTime($y['created_at']))->format('d M Y, H:i') ?>
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <?php if ($y['status'] === 'UPCOMING'): ?>
                                            <button
                                                onclick="confirmTransition(<?= (int) $y['id'] ?>, '<?= htmlspecialchars($y['year_label'], ENT_QUOTES) ?>')"
                                                class="px-3 py-1.5 bg-emerald-50 text-emerald-600 text-xs font-bold rounded-lg hover:bg-emerald-100 transition cursor-pointer flex items-center gap-1"
                                                title="Activate this year"
                                            >
                                                🚀 Activate
                                            </button>
                                            <?php endif; ?>
                                            <?php if ($y['status'] === 'ACTIVE'): ?>
                                            <span class="px-3 py-1.5 bg-emerald-50 text-emerald-400 text-xs font-bold rounded-lg cursor-default flex items-center gap-1">
                                                ✓ Active
                                            </span>
                                            <?php endif; ?>
                                            <?php if ($y['status'] === 'ARCHIVED'): ?>
                                            <span class="px-3 py-1.5 bg-slate-50 text-slate-400 text-xs font-bold rounded-lg cursor-default">
                                                Archived
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>

            </div>
        </main>
    </div>
</div>

<!-- ══════ ADD ACADEMIC YEAR MODAL ══════ -->
<div id="addYearModal" class="hidden fixed inset-0 z-[999] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="document.getElementById('addYearModal').classList.add('hidden')"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg z-10 overflow-hidden">
        <!-- Modal Header -->
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-black text-slate-800 uppercase tracking-wider flex items-center gap-2">
                <span class="p-1 bg-indigo-50 text-indigo-600 rounded">📅</span> Add New Academic Year
            </h3>
            <button onclick="document.getElementById('addYearModal').classList.add('hidden')" class="w-7 h-7 rounded-lg bg-slate-100 hover:bg-slate-200 flex items-center justify-center text-slate-500 font-bold transition cursor-pointer">✕</button>
        </div>
        <!-- Modal Body -->
        <form id="createForm" class="p-6 space-y-4">
            <div>
                <label class="block text-xs font-bold text-slate-500 mb-1.5">Year Label *</label>
                <input
                    type="text"
                    name="year_label"
                    id="create_year_label"
                    required
                    placeholder="e.g. 2027-2028"
                    pattern="^\d{4}-\d{4}$"
                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2.5 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-400 transition font-mono"
                >
                <p class="text-caption text-slate-400 mt-1">Format: YYYY-YYYY (e.g. 2027-2028)</p>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-500 mb-1.5">Start Date *</label>
                    <input
                        type="date"
                        name="start_date"
                        id="create_start_date"
                        required
                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2.5 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-400 transition"
                    >
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 mb-1.5">End Date *</label>
                    <input
                        type="date"
                        name="end_date"
                        id="create_end_date"
                        required
                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2.5 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-400 transition"
                    >
                </div>
            </div>
            <div class="bg-slate-50 border border-slate-200 rounded-xl p-3">
                <div class="flex items-center gap-2 text-xs text-slate-500">
                    <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span>New years are created with <strong>UPCOMING</strong> status. Use "Activate" to make it the current year.</span>
                </div>
            </div>
            <div id="createError" class="hidden bg-red-50 border border-red-200 text-red-600 text-xs font-semibold px-4 py-2.5 rounded-xl"></div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="document.getElementById('addYearModal').classList.add('hidden')" class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold text-xs rounded-xl transition cursor-pointer">Cancel</button>
                <button type="submit" id="createBtn" class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    Create Year
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ══════ TRANSITION CONFIRMATION MODAL ══════ -->
<div id="transitionModal" class="hidden fixed inset-0 z-[999] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="document.getElementById('transitionModal').classList.add('hidden')"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md z-10 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-black text-slate-800 uppercase tracking-wider flex items-center gap-2">
                <span class="p-1 bg-amber-50 text-amber-600 rounded">⚡</span> Transition Year
            </h3>
            <button onclick="document.getElementById('transitionModal').classList.add('hidden')" class="w-7 h-7 rounded-lg bg-slate-100 hover:bg-slate-200 flex items-center justify-center text-slate-500 font-bold transition cursor-pointer">✕</button>
        </div>
        <div class="p-6">
            <div class="text-center mb-6">
                <div class="w-16 h-16 rounded-2xl bg-amber-100 text-amber-600 flex items-center justify-center text-3xl mx-auto mb-4">⚡</div>
                <p class="text-sm text-slate-600 leading-relaxed">
                    This will <strong class="text-slate-800">archive the current active year</strong>
                    <?php if ($current_active): ?>
                        (<strong class="text-slate-800"><?= htmlspecialchars($current_active['year_label']) ?></strong>)
                    <?php endif; ?>
                    and <strong class="text-emerald-600">activate</strong>
                    <strong class="text-slate-800" id="transitionTargetLabel"></strong>.
                </p>
                <p class="text-xs text-slate-400 mt-2">Active students from the current year will be archived. This action can be reversed by the database administrator.</p>
            </div>
            <div id="transitionError" class="hidden bg-red-50 border border-red-200 text-red-600 text-xs font-semibold px-4 py-2.5 rounded-xl mb-4"></div>
            <div class="flex justify-end gap-2">
                <button onclick="document.getElementById('transitionModal').classList.add('hidden')" class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold text-xs rounded-xl transition cursor-pointer">Cancel</button>
                <button id="transitionBtn" onclick="executeTransition()" class="px-5 py-2.5 bg-amber-500 hover:bg-amber-600 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer flex items-center gap-2">
                    ⚡ Confirm Transition
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// ── Create Year ──────────────────────────────────────────────────
document.getElementById('createForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var btn = document.getElementById('createBtn');
    var errDiv = document.getElementById('createError');
    btn.disabled = true;
    btn.innerHTML = '<svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg> Creating...';
    errDiv.classList.add('hidden');

    var fd = new FormData(this);
    fetch('api/create_year.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                document.getElementById('addYearModal').classList.add('hidden');
                showToast('success', data.message || 'Year created successfully.');
                setTimeout(function() { location.reload(); }, 800);
            } else {
                errDiv.textContent = data.error || 'Failed to create year.';
                errDiv.classList.remove('hidden');
            }
        })
        .catch(function(err) {
            errDiv.textContent = 'Network error. Please try again.';
            errDiv.classList.remove('hidden');
        })
        .finally(function() {
            btn.disabled = false;
            btn.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg> Create Year';
        });
});

// ── Auto-fill dates from label ───────────────────────────────────
document.getElementById('create_year_label').addEventListener('input', function() {
    var match = this.value.match(/^(\d{4})-(\d{4})$/);
    if (match) {
        document.getElementById('create_start_date').value = match[1] + '-09-01';
        document.getElementById('create_end_date').value = match[2] + '-08-31';
    }
});

// ── Transition ───────────────────────────────────────────────────
var pendingTransitionId = null;

function confirmTransition(id, label) {
    pendingTransitionId = id;
    document.getElementById('transitionTargetLabel').textContent = label;
    document.getElementById('transitionError').classList.add('hidden');
    document.getElementById('transitionModal').classList.remove('hidden');
}

function executeTransition() {
    if (!pendingTransitionId) return;
    var btn = document.getElementById('transitionBtn');
    var errDiv = document.getElementById('transitionError');
    btn.disabled = true;
    btn.innerHTML = '<svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg> Transitioning...';
    errDiv.classList.add('hidden');

    var fd = new FormData();
    fd.append('upcoming_year_id', pendingTransitionId);

    fetch('transition_year.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                document.getElementById('transitionModal').classList.add('hidden');
                showToast('success', data.message || 'Year transitioned successfully.');
                setTimeout(function() { location.reload(); }, 800);
            } else {
                errDiv.textContent = data.error || 'Transition failed.';
                errDiv.classList.remove('hidden');
            }
        })
        .catch(function(err) {
            errDiv.textContent = 'Network error. Please try again.';
            errDiv.classList.remove('hidden');
        })
        .finally(function() {
            btn.disabled = false;
            btn.innerHTML = '⚡ Confirm Transition';
            pendingTransitionId = null;
        });
}

// ── Toast ────────────────────────────────────────────────────────
function showToast(type, message) {
    var toast = document.getElementById('toast');
    var bg = type === 'success'
        ? 'bg-emerald-50 border border-emerald-200 text-emerald-700'
        : 'bg-red-50 border border-red-200 text-red-700';
    var icon = type === 'success' ? '✅' : '❌';
    toast.className = bg + ' px-4 py-3 rounded-xl shadow-lg flex items-center gap-2 text-sm font-semibold transition-all duration-300';
    toast.innerHTML = '<span>' + icon + '</span> ' + message;
    toast.classList.remove('hidden');
    setTimeout(function() { toast.classList.add('hidden'); }, 3000);
}
</script>

</body>
</html>
