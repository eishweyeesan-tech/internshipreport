<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/academic_year_helper.php';

$admin_name = $_SESSION['username'] ?? 'Admin';
$admin_id   = (int) ($_SESSION['user_id'] ?? 0);
$db         = $mysqli ?? $conn;

ensure_academic_years_table($db);
ensure_supervisor_assignments_table($db);

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

// Fetch all academic years with student counts from users table and supervisor counts dynamically from student_profiles
$ay_rows = $db->query("
    SELECT ay.*,
           (SELECT COUNT(*) FROM users u WHERE u.role = 'student' AND (u.academic_year_id = ay.id OR u.academic_year = ay.year_label)) AS student_count,
           (SELECT COUNT(DISTINCT sp.supervisor_id)
            FROM student_profiles sp
            JOIN users stu ON stu.id = sp.user_id AND stu.role = 'student'
            WHERE sp.supervisor_id IS NOT NULL
              AND (stu.academic_year_id = ay.id OR stu.academic_year = ay.year_label)
           ) AS supervisor_count
    FROM academic_years ay
    ORDER BY ay.start_date DESC, ay.year_label DESC
");
$years = $ay_rows ? $ay_rows->fetch_all(MYSQLI_ASSOC) : [];

$current_active = null;
foreach ($years as $y) {
    if ((int)$y['is_current'] === 1) {
        $current_active = $y;
        break;
    }
}
if (!$current_active) {
    foreach ($years as $y) {
        if (strtoupper($y['status']) === 'ACTIVE') {
            $current_active = $y;
            break;
        }
    }
}
if (!$current_active && !empty($years)) {
    $current_active = $years[0];
}

$selected_year = null;
$selected_year_history = [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Academic Years – Admin – InternReport</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script>
    (function() {
        var theme = localStorage.getItem('theme');
        if (theme === 'dark') document.documentElement.classList.add('dark');
    })();
    tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                fontFamily: {
                    'sans': ['Inter', 'sans-serif'],
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
</head>
<body class="bg-slate-50 font-inter antialiased">

<div class="flex h-screen overflow-hidden">

    <?php $activePage = 'academic-years'; ?>
    <?php require_once __DIR__ . '/../includes/admin-sidebar.php'; ?>

    <!-- ─── MAIN ─── -->
    <div class="flex-1 flex flex-col overflow-hidden">

        <?php $pageTitle = '📅 Academic Years Management'; require_once __DIR__ . '/../includes/topbar.php'; ?>

        <main class="flex-1 overflow-y-auto scroll-smooth p-4 lg:p-6" style="scrollbar-gutter: stable;">

            <!-- Success/Error Toast -->
            <div id="toast" class="hidden fixed top-6 right-6 z-[100] max-w-sm"></div>

            <div class="w-full space-y-6 pb-16">

                <!-- Active Year Banner -->
                <?php if ($current_active): ?>
                <div class="bg-gradient-to-r from-emerald-500 to-teal-600 rounded-2xl p-5 text-white shadow-lg shadow-emerald-500/20">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-white/20 backdrop-blur-sm flex items-center justify-center text-xl border border-white/30 shrink-0">
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
                        <div class="shrink-0">
                            <button
                                onclick="document.getElementById('addYearModal').classList.remove('hidden')"
                                class="px-4 py-2.5 bg-white text-teal-800 hover:bg-emerald-50 font-bold text-xs rounded-xl shadow-xs transition cursor-pointer flex items-center gap-2"
                            >
                                <svg class="w-4 h-4 text-teal-700" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                                Add New Year
                            </button>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <span class="text-xl">⚠️</span>
                        <div>
                            <p class="text-sm font-bold text-amber-700">No active academic year</p>
                            <p class="text-xs text-amber-500">Select "Set Active" on an academic year to set the active session.</p>
                        </div>
                    </div>
                    <button
                        onclick="document.getElementById('addYearModal').classList.remove('hidden')"
                        class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white font-bold text-xs rounded-xl shadow-xs transition cursor-pointer flex items-center gap-2"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                        Add New Year
                    </button>
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
                                    <th class="px-5 py-3 text-center">Current Session</th>
                                    <th class="px-5 py-3 text-center">Students</th>
                                    <th class="px-5 py-3 text-center">Supervisors</th>
                                    <th class="px-5 py-3 text-left">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($years as $y): ?>
                                <?php
                                    $is_current = (int) $y['is_current'] === 1;
                                    $status_str = strtoupper($y['status']);
                                ?>
                                <tr class="hover:bg-slate-50/50 transition <?= $is_current ? 'bg-emerald-50/30' : '' ?>">
                                    <td class="px-5 py-4">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-xl <?= $is_current ? 'bg-emerald-100 text-emerald-600' : 'bg-slate-100 text-slate-500' ?> flex items-center justify-center text-sm font-bold shrink-0">
                                                📅
                                            </div>
                                            <div>
                                                <p class="font-bold text-slate-800"><?= htmlspecialchars($y['year_label']) ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 text-slate-600 whitespace-nowrap">
                                        <?= (new DateTime($y['start_date']))->format('d M Y') ?> – <?= (new DateTime($y['end_date']))->format('d M Y') ?>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <?php if ($is_current): ?>
                                        <span class="inline-flex items-center gap-1.5 text-xs font-bold text-emerald-700 bg-emerald-50 px-2.5 py-1 rounded-full border border-emerald-200">
                                            <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span> Current
                                        </span>
                                        <?php elseif ($status_str === 'ARCHIVED'): ?>
                                        <span class="inline-flex items-center gap-1 text-xs font-bold text-slate-500 bg-slate-100 px-2.5 py-0.5 rounded-full border border-slate-200">
                                            📦 Archived
                                        </span>
                                        <?php else: ?>
                                        <span class="text-slate-300 text-xs">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <span class="font-bold text-slate-700"><?= $y['student_count'] ?></span>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <span class="font-bold text-slate-700"><?= $y['supervisor_count'] ?></span>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="flex items-center gap-2 flex-nowrap">
                                            <button type="button"
                                                    onclick="openYearDetailsModal(<?= (int)$y['id'] ?>, '<?= htmlspecialchars($y['year_label'], ENT_QUOTES) ?>')"
                                                    class="inline-flex items-center justify-center gap-1.5 px-3 py-1.5 w-[86px] h-7.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-bold text-xs rounded-lg border border-indigo-200/70 shadow-xs transition cursor-pointer shrink-0"
                                                    title="View year details & statistics">
                                                <i class="fa-regular fa-eye text-xs"></i>
                                                <span>Details</span>
                                            </button>

                                            <?php if (!$is_current): ?>
                                            <button type="button"
                                                    onclick="setActiveYear(<?= (int)$y['id'] ?>, '<?= htmlspecialchars($y['year_label']) ?>')"
                                                    class="inline-flex items-center justify-center gap-1.5 px-3 py-1.5 w-[102px] h-7.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs rounded-lg shadow-xs transition cursor-pointer shrink-0"
                                                    title="Set as current active academic year">
                                                <i class="fa-solid fa-star text-[11px]"></i>
                                                <span>Set Active</span>
                                            </button>
                                            <?php endif; ?>

                                            <?php if ($status_str !== 'ARCHIVED'): ?>
                                            <button type="button"
                                                    onclick="confirmArchiveYear(<?= (int)$y['id'] ?>, '<?= htmlspecialchars($y['year_label']) ?>', <?= (int)$y['student_count'] ?>)"
                                                    class="inline-flex items-center justify-center gap-1.5 px-3 py-1.5 w-[102px] h-7.5 bg-amber-500 hover:bg-amber-600 text-white font-bold text-xs rounded-lg shadow-xs transition cursor-pointer shrink-0"
                                                    title="Archive academic year">
                                                <i class="fa-solid fa-box-archive text-xs"></i>
                                                <span>Archive</span>
                                            </button>
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
<div id="addYearModal" class="hidden fixed inset-0 z-[999] flex items-center justify-center p-4" role="dialog" aria-modal="true">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="document.getElementById('addYearModal').classList.add('hidden')"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg z-10 overflow-hidden">
        <!-- Modal Header -->
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-black text-slate-800 uppercase tracking-wider flex items-center gap-2">
                <span class="p-1 bg-indigo-50 text-indigo-600 rounded">📅</span> Add New Academic Year
            </h3>
            <button type="button" onclick="document.getElementById('addYearModal').classList.add('hidden')" class="w-7 h-7 rounded-lg bg-slate-100 hover:bg-slate-200 flex items-center justify-center text-slate-500 font-bold transition cursor-pointer">✕</button>
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
                    placeholder="e.g. 2024-2025"
                    pattern="^\d{4}-\d{4}$"
                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2.5 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-400 transition font-mono"
                >
                <p class="text-caption text-slate-400 mt-1">Format: YYYY-YYYY (e.g. 2024-2025)</p>
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
            <div class="bg-indigo-50/60 border border-indigo-100 rounded-xl p-3.5 flex items-center justify-between">
                <div>
                    <label for="set_as_current" class="text-xs font-extrabold text-slate-800 cursor-pointer flex items-center gap-2">
                        ⭐ Set as Current Academic Year
                    </label>
                    <p class="text-micro text-slate-500 mt-0.5">Automatically archives previous active session without losing student history.</p>
                </div>
                <input type="checkbox" name="set_as_current" id="set_as_current" value="1" class="w-4 h-4 text-indigo-600 rounded border-slate-300 focus:ring-indigo-500 cursor-pointer">
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

<!-- ══════ ARCHIVE ACADEMIC YEAR MODAL ══════ -->
<div id="archiveYearModal" class="hidden fixed inset-0 z-[999] flex items-center justify-center p-4" role="dialog" aria-modal="true">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="document.getElementById('archiveYearModal').classList.add('hidden')"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md z-10 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-black text-slate-800 uppercase tracking-wider flex items-center gap-2">
                <span class="p-1 bg-amber-50 text-amber-600 rounded">📦</span> Archive Academic Year
            </h3>
            <button type="button" onclick="document.getElementById('archiveYearModal').classList.add('hidden')" class="w-7 h-7 rounded-lg bg-slate-100 hover:bg-slate-200 flex items-center justify-center text-slate-500 font-bold transition cursor-pointer">✕</button>
        </div>
        <div class="p-6 space-y-4">
            <p class="text-sm text-slate-600">
                Are you sure you want to archive academic year <strong id="archive_year_label_display" class="text-slate-800 font-bold"></strong>?
            </p>
            <div class="bg-amber-50 border border-amber-200 rounded-xl p-3.5 text-xs text-amber-700 font-medium">
                ⚠️ This will set the year status to <strong>Archived</strong> and batch update all <strong id="archive_student_count_display">0</strong> student(s) belonging to this year to <strong>Archived</strong> status. Supervisor accounts will <strong>NOT</strong> be affected — they remain permanently active.
            </div>
            <div id="archiveError" class="hidden bg-red-50 border border-red-200 text-red-600 text-xs font-semibold px-4 py-2.5 rounded-xl"></div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="document.getElementById('archiveYearModal').classList.add('hidden')" class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold text-xs rounded-xl transition cursor-pointer">Cancel</button>
                <button type="button" id="confirmArchiveBtn" onclick="doArchiveYear()" class="px-5 py-2.5 bg-amber-600 hover:bg-amber-700 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer flex items-center gap-2">
                    📦 Archive Year & Students
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ══════ YEAR DETAILS MODAL ══════ -->
<div id="yearDetailsModal" class="hidden fixed inset-0 z-[999] flex items-center justify-center p-4" role="dialog" aria-modal="true">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeYearDetailsModal()"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-4xl z-10 overflow-hidden flex flex-col max-h-[90vh]">
        <!-- Header -->
        <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-indigo-50 via-white to-blue-50 flex items-center justify-between shrink-0">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-indigo-100 text-indigo-600 flex items-center justify-center text-lg shrink-0">📅</div>
                <div>
                    <h3 id="yearDetailsTitle" class="text-sm font-black text-slate-800 uppercase tracking-wider">Year Details</h3>
                    <p id="yearDetailsYearLabel" class="text-xs text-slate-500 font-medium mt-0.5">—</p>
                </div>
            </div>
            <button type="button" onclick="closeYearDetailsModal()" class="w-8 h-8 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center transition cursor-pointer shrink-0" aria-label="Close">✕</button>
        </div>

        <!-- Body -->
        <div class="p-6 overflow-y-auto flex-1 space-y-5" style="scrollbar-gutter: stable;">
            <input type="hidden" id="yearDetailsYearId" value="">

            <!-- Tab Navigation Header -->
            <div class="flex border-b border-slate-200 bg-slate-50/60 rounded-xl overflow-hidden p-1 gap-1">
                <button type="button" id="tabYearStudentsBtn" onclick="switchYearDetailsTab('students')" class="flex-1 py-2.5 px-4 text-xs font-bold text-indigo-700 bg-indigo-50 border-b-2 border-indigo-600 transition flex items-center justify-center gap-2 cursor-pointer">
                    <span>🎓 Students</span>
                    <span id="tabStuBadge" class="bg-indigo-100 text-indigo-800 text-[10px] font-black px-2 py-0.5 rounded-full">0</span>
                </button>
                <button type="button" id="tabYearSupervisorsBtn" onclick="switchYearDetailsTab('supervisors')" class="flex-1 py-2.5 px-4 text-xs font-bold text-slate-500 hover:text-slate-700 border-b-2 border-transparent transition flex items-center justify-center gap-2 cursor-pointer">
                    <span>👨‍🏫 Supervisors</span>
                    <span id="tabSupBadge" class="bg-slate-200 text-slate-700 text-[10px] font-black px-2 py-0.5 rounded-full">0</span>
                </button>
            </div>

            <!-- ── TAB PANE: STUDENTS ── -->
            <div id="paneYearStudents" class="space-y-4">
                <div class="flex items-center justify-between gap-3">
                    <h4 class="text-xs font-black text-slate-600 uppercase tracking-wider">Enrolled Students Roster</h4>
                    <div class="relative w-48 sm:w-64">
                        <input
                            type="text"
                            id="yearStudentSearchInput"
                            oninput="filterYearModalStudents(this.value)"
                            placeholder="Filter roll, name, company..."
                            class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-1.5 text-xs text-slate-700 placeholder:text-slate-400 focus:outline-none focus:border-indigo-400 transition"
                        >
                    </div>
                </div>

                <div id="yearStudentsTableWrap" class="border border-slate-200 rounded-xl overflow-hidden bg-white shadow-2xs">
                    <div class="max-h-72 overflow-y-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-slate-50 text-slate-500 font-bold uppercase tracking-wider text-[11px] sticky top-0 border-b border-slate-100">
                                <tr>
                                    <th class="px-4 py-2.5"># / Roll No</th>
                                    <th class="px-4 py-2.5">Student</th>
                                    <th class="px-4 py-2.5">Company & Role</th>
                                    <th class="px-4 py-2.5">Supervisor</th>
                                    <th class="px-4 py-2.5">Action</th>
                                </tr>
                            </thead>
                            <tbody id="yearStudentsTableBody" class="divide-y divide-slate-100"></tbody>
                        </table>
                    </div>
                </div>

                <div id="yearStudentsEmpty" class="hidden p-8 text-center bg-slate-50/50 rounded-2xl border border-dashed border-slate-200">
                    <div class="w-12 h-12 rounded-2xl bg-indigo-50 text-indigo-400 flex items-center justify-center text-xl mx-auto mb-2">🎓</div>
                    <p class="text-xs font-bold text-slate-600">No students enrolled in this academic year yet</p>
                    <p class="text-[11px] text-slate-400 mt-0.5">Students registered with this academic batch will automatically appear here.</p>
                </div>
            </div>

            <!-- ── TAB PANE: SUPERVISORS ── -->
            <div id="paneYearSupervisors" class="hidden space-y-4">
                <div class="flex items-center justify-between gap-3 bg-slate-50/80 p-3 rounded-xl border border-slate-200">
                    <div>
                        <h4 class="text-xs font-black text-slate-700 uppercase tracking-wider">👨‍🏫 Faculty Supervisors in this Academic Year</h4>
                        <p class="text-[11px] text-slate-500 mt-0.5">Supervisors who have students assigned under their supervision for this batch.</p>
                    </div>
                </div>

                <!-- Supervisors List -->
                <div>
                    <div id="yearSupervisorList" class="space-y-2"></div>
                    <div id="yearSupervisorEmpty" class="hidden p-8 text-center bg-slate-50/50 rounded-2xl border border-dashed border-slate-200">
                        <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-400 flex items-center justify-center text-xl mx-auto mb-2">👨‍🏫</div>
                        <p class="text-xs font-bold text-slate-600">No active supervisors in this academic year yet</p>
                        <p class="text-[11px] text-slate-400 mt-0.5">When students register and select their supervisor for this year, supervisors will automatically appear here.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="px-6 py-3 border-t border-slate-100 bg-slate-50 flex justify-end shrink-0">
            <button type="button" onclick="closeYearDetailsModal()" class="px-4 py-2 bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-bold rounded-xl transition cursor-pointer">Close</button>
        </div>
    </div>
</div>

<!-- ══════ TOAST NOTIFICATION CONTAINER ══════ -->
<div id="toast" class="hidden fixed bottom-5 right-5 z-[9999] px-4 py-3 rounded-xl shadow-xl transition-all duration-300 max-w-sm flex items-center gap-2 text-sm font-semibold"></div>

<script>
// Global variables
window.targetArchiveId = 0;
window.currentYearStudentsData = [];

// ── Helper functions ──
function escHtml(s) { var d = document.createElement('div'); d.appendChild(document.createTextNode(s || '')); return d.innerHTML; }
function escAttr(s) { return String(s || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }

// ── Toast Notification ──
window.showToast = function(type, message) {
    var toast = document.getElementById('toast');
    if (!toast) {
        alert(message);
        return;
    }
    var bg = type === 'success'
        ? 'bg-emerald-50 border border-emerald-200 text-emerald-700'
        : 'bg-red-50 border border-red-200 text-red-700';
    var icon = type === 'success' ? '✅' : '❌';
    toast.className = bg + ' px-4 py-3 rounded-xl shadow-xl fixed bottom-5 right-5 z-[9999] flex items-center gap-2 text-sm font-semibold transition-all duration-300';
    toast.innerHTML = '<span>' + icon + '</span> <span>' + escHtml(message) + '</span>';
    toast.classList.remove('hidden');
    setTimeout(function() { if (toast) toast.classList.add('hidden'); }, 3500);
};

// ── Year Details Modal ──
window.openYearDetailsModal = function(yearId, yearLabel) {
    var modal = document.getElementById('yearDetailsModal');
    if (!modal) return;
    
    var titleEl = document.getElementById('yearDetailsTitle');
    var idEl = document.getElementById('yearDetailsYearId');
    var lblEl = document.getElementById('yearDetailsYearLabel');
    var errDiv = document.getElementById('assignToYearError');
    
    if (titleEl) titleEl.textContent = 'Year Details: ' + yearLabel;
    if (idEl) idEl.value = yearId;
    if (lblEl) lblEl.textContent = yearLabel;
    if (errDiv) errDiv.classList.add('hidden');
    
    // Default to students tab
    window.switchYearDetailsTab('students');

    // Fetch year details via AJAX
    var fd = new FormData();
    fd.append('action', 'get_year_details');
    fd.append('academic_year_id', yearId);

    fetch('api/assign_supervisor.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data && data.success) {
                var stuCount = (data.student_count !== undefined) ? data.student_count : (data.students ? data.students.length : 0);
                var supCount = (data.supervisor_count !== undefined) ? data.supervisor_count : (data.supervisors ? data.supervisors.length : 0);
                
                var stCntEl = document.getElementById('yearDetailsStudentCount');
                var supCntEl = document.getElementById('yearDetailsSupervisorCount');
                var stBdg = document.getElementById('tabStuBadge');
                var supBdg = document.getElementById('tabSupBadge');
                
                if (stCntEl) stCntEl.textContent = stuCount;
                if (supCntEl) supCntEl.textContent = supCount;
                if (stBdg) stBdg.textContent = stuCount;
                if (supBdg) supBdg.textContent = supCount;
                
                window.currentYearStudentsData = data.students || [];
                window.renderYearStudents(window.currentYearStudentsData);
                window.renderYearSupervisors(data.supervisors || []);
            } else {
                window.renderYearStudents([]);
                window.renderYearSupervisors([]);
            }
        })
        .catch(function(err) {
            console.error('Error loading year details:', err);
            window.renderYearStudents([]);
            window.renderYearSupervisors([]);
        });

    modal.classList.remove('hidden');
    document.body.classList.add('overflow-hidden');
};

window.closeYearDetailsModal = function() {
    var modal = document.getElementById('yearDetailsModal');
    if (modal) {
        modal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    }
};

window.switchYearDetailsTab = function(tabName) {
    var tabStuBtn = document.getElementById('tabYearStudentsBtn');
    var tabSupBtn = document.getElementById('tabYearSupervisorsBtn');
    var paneStu = document.getElementById('paneYearStudents');
    var paneSup = document.getElementById('paneYearSupervisors');

    if (!tabStuBtn || !tabSupBtn || !paneStu || !paneSup) return;

    if (tabName === 'students') {
        tabStuBtn.className = 'flex-1 py-2.5 px-4 text-xs font-bold text-indigo-700 bg-indigo-50 border-b-2 border-indigo-600 transition flex items-center justify-center gap-2 cursor-pointer';
        tabSupBtn.className = 'flex-1 py-2.5 px-4 text-xs font-bold text-slate-500 hover:text-slate-700 border-b-2 border-transparent transition flex items-center justify-center gap-2 cursor-pointer';
        paneStu.classList.remove('hidden');
        paneSup.classList.add('hidden');
    } else {
        tabSupBtn.className = 'flex-1 py-2.5 px-4 text-xs font-bold text-emerald-700 bg-emerald-50 border-b-2 border-emerald-600 transition flex items-center justify-center gap-2 cursor-pointer';
        tabStuBtn.className = 'flex-1 py-2.5 px-4 text-xs font-bold text-slate-500 hover:text-slate-700 border-b-2 border-transparent transition flex items-center justify-center gap-2 cursor-pointer';
        paneSup.classList.remove('hidden');
        paneStu.classList.add('hidden');
    }
};

window.renderYearStudents = function(students) {
    var tbody = document.getElementById('yearStudentsTableBody');
    var emptyEl = document.getElementById('yearStudentsEmpty');
    var tableWrap = document.getElementById('yearStudentsTableWrap');
    var searchInput = document.getElementById('yearStudentSearchInput');
    if (searchInput) searchInput.value = '';

    if (!students || students.length === 0) {
        if (tbody) tbody.innerHTML = '';
        if (tableWrap) tableWrap.classList.add('hidden');
        if (emptyEl) emptyEl.classList.remove('hidden');
        return;
    }

    if (tableWrap) tableWrap.classList.remove('hidden');
    if (emptyEl) emptyEl.classList.add('hidden');

    var html = '';
    students.forEach(function(s, idx) {
        var searchStr = ((s.student_roll || '') + ' ' + (s.full_name || '') + ' ' + (s.username || '') + ' ' + (s.company_name || '') + ' ' + (s.supervisor_name || '') + ' ' + (s.email || '')).toLowerCase();
        var initial = (s.full_name || s.username || 'S').charAt(0).toUpperCase();

        html += '<tr class="year-modal-stu-row hover:bg-slate-50 transition" data-search="' + escAttr(searchStr) + '">';
        html += '  <td class="px-4 py-3 text-xs">';
        html += '    <div class="flex items-center gap-1.5">';
        html += '      <span class="text-slate-400 font-mono text-[11px] w-4">' + (idx + 1) + '</span>';
        html += '      <span class="font-mono font-bold text-slate-800 bg-slate-100 px-2 py-0.5 rounded border border-slate-200">' + escHtml(s.student_roll || s.username) + '</span>';
        html += '    </div>';
        html += '  </td>';
        html += '  <td class="px-4 py-3">';
        html += '    <div class="flex items-center gap-2">';
        html += '      <div class="w-7 h-7 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center text-xs font-bold shrink-0">' + initial + '</div>';
        html += '      <div class="min-w-0">';
        html += '        <p class="font-bold text-xs text-slate-800 truncate">' + escHtml(s.full_name || s.username) + '</p>';
        html += '        <p class="text-[11px] text-slate-400 truncate">' + escHtml(s.email || '—') + '</p>';
        html += '      </div>';
        html += '    </div>';
        html += '  </td>';
        html += '  <td class="px-4 py-3 text-xs text-slate-700">';
        html += '    <p class="font-semibold truncate max-w-[140px]">' + escHtml(s.company_name || 'Unassigned') + '</p>';
        if (s.job_role) {
            html += '    <p class="text-[11px] text-slate-400 truncate max-w-[140px]">' + escHtml(s.job_role) + '</p>';
        }
        html += '  </td>';
        html += '  <td class="px-4 py-3 text-xs text-slate-600 whitespace-nowrap">';
        html += '    <span class="font-medium">' + escHtml(s.supervisor_name || 'Unassigned') + '</span>';
        html += '  </td>';
        html += '  <td class="px-4 py-3 text-xs">';
        html += '    <a href="../view_student_history.php?uid=' + s.id + '" class="inline-flex items-center gap-1 px-2.5 py-1 text-[11px] font-bold text-indigo-700 bg-indigo-50 hover:bg-indigo-100 border border-indigo-200/60 rounded-lg shadow-2xs transition">';
        html += '      <i class="fa-regular fa-eye"></i> View';
        html += '    </a>';
        html += '  </td>';
        html += '</tr>';
    });

    if (tbody) tbody.innerHTML = html;
};

window.filterYearModalStudents = function(query) {
    query = (query || '').toLowerCase().trim();
    var rows = document.querySelectorAll('.year-modal-stu-row');
    rows.forEach(function(r) {
        var s = r.getAttribute('data-search') || '';
        if (!query || s.indexOf(query) !== -1) {
            r.style.display = '';
        } else {
            r.style.display = 'none';
        }
    });
};

window.renderYearSupervisors = function(supervisors) {
    var listEl = document.getElementById('yearSupervisorList');
    var emptyEl = document.getElementById('yearSupervisorEmpty');

    if (!listEl) return;

    if (!supervisors || supervisors.length === 0) {
        listEl.innerHTML = '';
        if (emptyEl) emptyEl.classList.remove('hidden');
        return;
    }
    if (emptyEl) emptyEl.classList.add('hidden');

    var html = '';
    supervisors.forEach(function(s) {
        var isInactive = (s.status && s.status.toLowerCase() === 'inactive');
        var statusPill = isInactive
            ? '<span class="inline-flex items-center gap-1 text-[10px] font-bold text-red-700 bg-red-50 border border-red-200 px-1.5 py-0.5 rounded-full"><span class="w-1 h-1 rounded-full bg-red-500"></span> Inactive</span>'
            : '<span class="inline-flex items-center gap-1 text-[10px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 px-1.5 py-0.5 rounded-full"><span class="w-1 h-1 rounded-full bg-emerald-500"></span> Active</span>';

        html += '<div class="flex items-center gap-3 bg-white border border-slate-200 rounded-xl p-3.5 hover:bg-slate-50/80 transition shadow-2xs">';
        html += '  <div class="w-10 h-10 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center text-sm font-bold shrink-0">';
        html += '    ' + (s.username ? s.username.charAt(0) : 'S').toUpperCase();
        html += '  </div>';
        html += '  <div class="flex-1 min-w-0">';
        html += '    <div class="flex items-center gap-2">';
        html += '      <p class="font-bold text-sm text-slate-800">' + escHtml(s.username) + '</p>';
        html += '      ' + statusPill;
        html += '    </div>';
        html += '    <p class="text-xs text-slate-500 mt-0.5">' + escHtml(s.email || '') + (s.department ? ' · ' + escHtml(s.department) : '') + '</p>';
        html += '  </div>';
        html += '  <div class="text-right shrink-0">';
        html += '    <span class="inline-flex items-center gap-1 text-xs font-bold text-emerald-800 bg-emerald-50 border border-emerald-200/80 px-2.5 py-1 rounded-lg shadow-2xs">';
        html += '      🎓 ' + (s.student_count || 0) + ' student(s) supervised';
        html += '    </span>';
        html += '  </div>';
        html += '</div>';
    });
    listEl.innerHTML = html;
};

// ── Set Active Year ──
window.setActiveYear = function(id, label) {
    if (!confirm('Set academic year "' + label + '" as the active current year?\n\nThis will automatically archive previous academic years and their students.')) return;
    
    var fd = new FormData();
    fd.append('id', id);
    fd.append('year_label', label);

    fetch('api/set_active_year.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                window.showToast('success', data.message || 'Active year updated.');
                setTimeout(function() { location.reload(); }, 800);
            } else {
                window.showToast('error', data.error || 'Failed to update active year.');
            }
        })
        .catch(function() {
            window.showToast('error', 'Network error setting active year.');
        });
};

// ── Archive Year Modal Handlers ──
window.confirmArchiveYear = function(id, label, studentCount) {
    window.targetArchiveId = id;
    var lblEl = document.getElementById('archive_year_label_display');
    var cntEl = document.getElementById('archive_student_count_display');
    var errEl = document.getElementById('archiveError');
    var modal = document.getElementById('archiveYearModal');
    
    if (lblEl) lblEl.textContent = label;
    if (cntEl) cntEl.textContent = studentCount;
    if (errEl) errEl.classList.add('hidden');
    if (modal) modal.classList.remove('hidden');
};

window.doArchiveYear = function() {
    if (!window.targetArchiveId) return;
    var btn = document.getElementById('confirmArchiveBtn');
    var errDiv = document.getElementById('archiveError');
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Archiving...';
    }
    if (errDiv) errDiv.classList.add('hidden');

    var fd = new FormData();
    fd.append('id', window.targetArchiveId);

    fetch('api/archive_year.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                var modal = document.getElementById('archiveYearModal');
                if (modal) modal.classList.add('hidden');
                window.showToast('success', data.message || 'Academic year archived.');
                setTimeout(function() { location.reload(); }, 800);
            } else {
                if (errDiv) {
                    errDiv.textContent = data.error || 'Failed to archive year.';
                    errDiv.classList.remove('hidden');
                }
            }
        })
        .catch(function() {
            if (errDiv) {
                errDiv.textContent = 'Network error while archiving.';
                errDiv.classList.remove('hidden');
            }
        })
        .finally(function() {
            if (btn) {
                btn.disabled = false;
                btn.textContent = '📦 Archive Year & Students';
            }
        });
};

// ── DOMContentLoaded Event Listeners ──
document.addEventListener('DOMContentLoaded', function() {
    var createForm = document.getElementById('createForm');
    if (createForm) {
        createForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = document.getElementById('createBtn');
            var errDiv = document.getElementById('createError');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg> Creating...';
            }
            if (errDiv) errDiv.classList.add('hidden');

            var fd = new FormData(this);
            fetch('api/create_year.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        var addModal = document.getElementById('addYearModal');
                        if (addModal) addModal.classList.add('hidden');
                        window.showToast('success', data.message || 'Year created successfully.');
                        setTimeout(function() { location.reload(); }, 800);
                    } else {
                        if (errDiv) {
                            errDiv.textContent = data.error || 'Failed to create year.';
                            errDiv.classList.remove('hidden');
                        }
                    }
                })
                .catch(function() {
                    if (errDiv) {
                        errDiv.textContent = 'Network error. Please try again.';
                        errDiv.classList.remove('hidden');
                    }
                })
                .finally(function() {
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg> Create Year';
                    }
                });
        });
    }

    var labelInput = document.getElementById('create_year_label');
    if (labelInput) {
        labelInput.addEventListener('input', function() {
            var match = this.value.match(/^(\d{4})-(\d{4})$/);
            if (match) {
                var sDate = document.getElementById('create_start_date');
                var eDate = document.getElementById('create_end_date');
                if (sDate) sDate.value = match[1] + '-09-01';
                if (eDate) eDate.value = match[2] + '-08-31';
            }
        });
    }
});
</script>

</body>
</html>
