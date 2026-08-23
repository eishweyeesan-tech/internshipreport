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

// Fetch all academic years with student counts from users table and supervisor counts from assignments table
$ay_rows = $db->query("
    SELECT ay.*,
           (SELECT COUNT(*) FROM users u WHERE u.role = 'student' AND (u.academic_year_id = ay.id OR u.academic_year = ay.year_label)) AS student_count,
           (SELECT COUNT(*) FROM supervisor_academic_assignments saa WHERE saa.academic_year_id = ay.id) AS supervisor_count
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

        <main class="flex-1 overflow-y-auto p-4 lg:p-6" style="scrollbar-gutter: stable;">

            <!-- Success/Error Toast -->
            <div id="toast" class="hidden fixed top-6 right-6 z-[100] max-w-sm"></div>

            <div class="w-full space-y-6">

                <!-- Header Row -->
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-black text-slate-800">Academic Years</h2>
                        <p class="text-sm text-slate-400 mt-0.5">Manage year sessions, transition active year, and archive completed sessions.</p>
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
                        <p class="text-xs text-amber-500">Select "Set Active" on an academic year to set the active session.</p>
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
                                    <th class="px-5 py-3 text-center">Current Session</th>
                                    <th class="px-5 py-3 text-center">Students</th>
                                    <th class="px-5 py-3 text-center">Supervisors</th>
                                    <th class="px-5 py-3 text-right">Actions</th>
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
                                    <td class="px-5 py-4 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <button type="button"
                                                    onclick="openYearDetailsModal(<?= (int)$y['id'] ?>, '<?= htmlspecialchars($y['year_label'], ENT_QUOTES) ?>')"
                                                    class="px-3 py-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-bold text-xs rounded-xl shadow-xs transition cursor-pointer flex items-center gap-1 border border-indigo-200/60">
                                                📋 Details
                                            </button>

                                            <?php if (!$is_current): ?>
                                            <button type="button"
                                                    onclick="setActiveYear(<?= (int)$y['id'] ?>, '<?= htmlspecialchars($y['year_label']) ?>')"
                                                    class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs rounded-xl shadow-xs transition cursor-pointer flex items-center gap-1">
                                                ⭐ Set Active
                                            </button>
                                            <?php endif; ?>

                                            <?php if ($status_str !== 'ARCHIVED'): ?>
                                            <button type="button"
                                                    onclick="confirmArchiveYear(<?= (int)$y['id'] ?>, '<?= htmlspecialchars($y['year_label']) ?>', <?= (int)$y['student_count'] ?>)"
                                                    class="px-3 py-1.5 bg-amber-500 hover:bg-amber-600 text-white font-bold text-xs rounded-xl shadow-xs transition cursor-pointer flex items-center gap-1">
                                                📦 Archive
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
<div id="archiveYearModal" class="hidden fixed inset-0 z-[999] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="document.getElementById('archiveYearModal').classList.add('hidden')"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md z-10 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-black text-slate-800 uppercase tracking-wider flex items-center gap-2">
                <span class="p-1 bg-amber-50 text-amber-600 rounded">📦</span> Archive Academic Year
            </h3>
            <button onclick="document.getElementById('archiveYearModal').classList.add('hidden')" class="w-7 h-7 rounded-lg bg-slate-100 hover:bg-slate-200 flex items-center justify-center text-slate-500 font-bold transition cursor-pointer">✕</button>
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

<script>
var targetArchiveId = 0;

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

// ── Set Active Year ──────────────────────────────────────────────
function setActiveYear(id, label) {
    if (!confirm('Set academic year "' + label + '" as the active current year?\n\nThis will automatically archive previous academic years and their students.')) return;
    
    var fd = new FormData();
    fd.append('id', id);
    fd.append('year_label', label);

    fetch('api/set_active_year.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                showToast('success', data.message || 'Active year updated.');
                setTimeout(function() { location.reload(); }, 800);
            } else {
                showToast('error', data.error || 'Failed to update active year.');
            }
        })
        .catch(function() {
            showToast('error', 'Network error setting active year.');
        });
}

// ── Confirm & Do Archive Year ────────────────────────────────────
function confirmArchiveYear(id, label, studentCount) {
    targetArchiveId = id;
    document.getElementById('archive_year_label_display').textContent = label;
    document.getElementById('archive_student_count_display').textContent = studentCount;
    document.getElementById('archiveError').classList.add('hidden');
    document.getElementById('archiveYearModal').classList.remove('hidden');
}

function doArchiveYear() {
    if (!targetArchiveId) return;
    var btn = document.getElementById('confirmArchiveBtn');
    var errDiv = document.getElementById('archiveError');
    btn.disabled = true;
    btn.textContent = 'Archiving...';
    errDiv.classList.add('hidden');

    var fd = new FormData();
    fd.append('id', targetArchiveId);

    fetch('api/archive_year.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                document.getElementById('archiveYearModal').classList.add('hidden');
                showToast('success', data.message || 'Academic year archived.');
                setTimeout(function() { location.reload(); }, 800);
            } else {
                errDiv.textContent = data.error || 'Failed to archive year.';
                errDiv.classList.remove('hidden');
            }
        })
        .catch(function() {
            errDiv.textContent = 'Network error while archiving.';
            errDiv.classList.remove('hidden');
        })
        .finally(function() {
            btn.disabled = false;
            btn.textContent = '📦 Archive Year & Students';
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

// ── Year Details Modal ───────────────────────────────────────────
function openYearDetailsModal(yearId, yearLabel) {
    var modal = document.getElementById('yearDetailsModal');
    if (!modal) return;
    document.getElementById('yearDetailsTitle').textContent = 'Year Details: ' + yearLabel;
    document.getElementById('yearDetailsYearId').value = yearId;
    document.getElementById('yearDetailsYearLabel').textContent = yearLabel;
    document.getElementById('assignToYearError').classList.add('hidden');

    // Fetch year details via AJAX
    var fd = new FormData();
    fd.append('action', 'get_year_details');
    fd.append('academic_year_id', yearId);

    fetch('api/assign_supervisor.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                document.getElementById('yearDetailsStudentCount').textContent = data.student_count || 0;
                document.getElementById('yearDetailsSupervisorCount').textContent = data.supervisor_count || 0;
                renderYearSupervisors(data.supervisors || []);
            } else {
                document.getElementById('yearDetailsStudentCount').textContent = '—';
                document.getElementById('yearDetailsSupervisorCount').textContent = '—';
                renderYearSupervisors([]);
            }
        })
        .catch(function() {
            document.getElementById('yearDetailsStudentCount').textContent = '—';
            document.getElementById('yearDetailsSupervisorCount').textContent = '—';
            renderYearSupervisors([]);
        });

    modal.classList.remove('hidden');
    document.body.classList.add('overflow-hidden');
}

function closeYearDetailsModal() {
    var modal = document.getElementById('yearDetailsModal');
    if (modal) {
        modal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    }
}

function renderYearSupervisors(supervisors) {
    var listEl = document.getElementById('yearSupervisorList');
    var emptyEl = document.getElementById('yearSupervisorEmpty');

    if (!supervisors || supervisors.length === 0) {
        listEl.innerHTML = '';
        emptyEl.classList.remove('hidden');
        return;
    }
    emptyEl.classList.add('hidden');

    var yearId = document.getElementById('yearDetailsYearId').value;
    var yearLabel = document.getElementById('yearDetailsYearLabel').textContent;

    var html = '';
    supervisors.forEach(function(s) {
        var isInactive = (s.status && s.status.toLowerCase() === 'inactive');
        var statusPill = isInactive
            ? '<span class="inline-flex items-center gap-1 text-[10px] font-bold text-red-700 bg-red-50 border border-red-200 px-1.5 py-0.5 rounded-full"><span class="w-1 h-1 rounded-full bg-red-500"></span> Inactive</span>'
            : '<span class="inline-flex items-center gap-1 text-[10px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 px-1.5 py-0.5 rounded-full"><span class="w-1 h-1 rounded-full bg-emerald-500"></span> Active</span>';

        html += '<div class="flex items-center gap-3 bg-white border border-slate-200 rounded-xl p-3.5 hover:bg-slate-50/80 transition">';
        html += '  <div class="w-10 h-10 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center text-sm font-bold shrink-0">';
        html += '    ' + (s.username.charAt(0) || 'S').toUpperCase();
        html += '  </div>';
        html += '  <div class="flex-1 min-w-0">';
        html += '    <div class="flex items-center gap-2">';
        html += '      <p class="font-bold text-sm text-slate-800">' + escHtml(s.username) + '</p>';
        html += '      ' + statusPill;
        html += '    </div>';
        html += '    <p class="text-xs text-slate-500 mt-0.5">' + escHtml(s.email || '') + (s.department ? ' · ' + escHtml(s.department) : '') + '</p>';
        html += '  </div>';
        html += '  <div class="text-right shrink-0 flex items-center gap-2">';
        html += '    <div>';
        html += '      <p class="text-xs font-bold text-emerald-700">' + (s.student_count || 0) + ' student(s)</p>';
        html += '    </div>';
        html += '    <button onclick="removeSupFromYear(' + (s.id || 0) + ', ' + yearId + ', \'' + escAttr(yearLabel) + '\')" ';
        html += '      class="px-2 py-1 bg-red-50 hover:bg-red-100 text-red-600 text-xs font-bold rounded-lg border border-red-200/60 transition cursor-pointer" title="Remove from this year">✕</button>';
        html += '  </div>';
        html += '</div>';
    });
    listEl.innerHTML = html;
}

function assignSupervisorToYear(e) {
    e.preventDefault();
    var yearId = document.getElementById('yearDetailsYearId').value;
    var supId = document.getElementById('assignToYearSelect').value;
    var errDiv = document.getElementById('assignToYearError');
    errDiv.classList.add('hidden');

    if (!yearId || !supId) {
        errDiv.textContent = 'Please select a supervisor.';
        errDiv.classList.remove('hidden');
        return false;
    }

    var fd = new FormData();
    fd.append('action', 'assign');
    fd.append('supervisor_id', supId);
    fd.append('academic_year_id', yearId);

    fetch('api/assign_supervisor.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                document.getElementById('assignToYearSelect').value = '';
                var yearLabel = document.getElementById('yearDetailsYearLabel').textContent;
                openYearDetailsModal(yearId, yearLabel);
            } else {
                errDiv.textContent = data.error || 'Failed to assign.';
                errDiv.classList.remove('hidden');
            }
        })
        .catch(function() {
            errDiv.textContent = 'Network error. Please try again.';
            errDiv.classList.remove('hidden');
        });
    return false;
}

function removeSupFromYear(supId, yearId, yearLabel) {
    if (!confirm('Remove supervisor from ' + yearLabel + '?')) return;
    var fd = new FormData();
    fd.append('action', 'unassign');
    fd.append('supervisor_id', supId);
    fd.append('academic_year_id', yearId);

    fetch('api/assign_supervisor.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                var yearLabelDisp = document.getElementById('yearDetailsYearLabel').textContent;
                openYearDetailsModal(yearId, yearLabelDisp);
                showToast('success', data.message || 'Supervisor removed.');
            } else {
                showToast('error', data.error || 'Failed to remove.');
            }
        })
        .catch(function() { showToast('error', 'Network error.'); });
}

function escHtml(s) { var d = document.createElement('div'); d.appendChild(document.createTextNode(s || '')); return d.innerHTML; }
</script>

<!-- ══════ YEAR DETAILS MODAL ══════ -->
<div id="yearDetailsModal" class="hidden fixed inset-0 z-[999] flex items-center justify-center p-4" role="dialog" aria-modal="true">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeYearDetailsModal()"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-3xl z-10 overflow-hidden flex flex-col max-h-[90vh]">
        <!-- Header -->
        <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-indigo-50 via-white to-blue-50 flex items-center justify-between shrink-0">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-indigo-100 text-indigo-600 flex items-center justify-center text-lg shrink-0">📅</div>
                <div>
                    <h3 id="yearDetailsTitle" class="text-sm font-black text-slate-800 uppercase tracking-wider">Year Details</h3>
                    <p id="yearDetailsYearLabel" class="text-xs text-slate-500 font-medium mt-0.5">—</p>
                </div>
            </div>
            <button onclick="closeYearDetailsModal()" class="w-8 h-8 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center transition cursor-pointer shrink-0">✕</button>
        </div>

        <!-- Body -->
        <div class="p-6 overflow-y-auto flex-1 space-y-5" style="scrollbar-gutter: stable;">
            <input type="hidden" id="yearDetailsYearId" value="">

            <!-- Stats -->
            <div class="grid grid-cols-2 gap-4">
                <div class="bg-indigo-50/60 border border-indigo-200/60 rounded-2xl p-4 text-center">
                    <p class="text-xs font-bold text-indigo-700 uppercase tracking-wider">Students</p>
                    <p id="yearDetailsStudentCount" class="text-2xl font-black text-indigo-800 mt-0.5">0</p>
                </div>
                <div class="bg-emerald-50/60 border border-emerald-200/60 rounded-2xl p-4 text-center">
                    <p class="text-xs font-bold text-emerald-700 uppercase tracking-wider">Supervisors</p>
                    <p id="yearDetailsSupervisorCount" class="text-2xl font-black text-emerald-800 mt-0.5">0</p>
                </div>
            </div>

            <!-- Assign Supervisor Form -->
            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                <h4 class="text-xs font-black text-slate-500 uppercase tracking-wider mb-3 flex items-center gap-1.5">
                    <span class="p-1 bg-emerald-50 text-emerald-600 rounded">➕</span> Assign Supervisor to This Year
                </h4>
                <form id="assignToYearForm" class="flex items-end gap-3" onsubmit="return assignSupervisorToYear(event)">
                    <div class="flex-1">
                        <label class="block text-xs font-bold text-slate-500 mb-1">Supervisor</label>
                        <select id="assignToYearSelect" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-400 transition" required>
                            <option value="">— Select Supervisor —</option>
                            <?php
                            $all_sup_q = $db->query("SELECT id, username, email, status FROM users WHERE role = 'supervisor' ORDER BY username ASC");
                            $all_supervisors = $all_sup_q ? $all_sup_q->fetch_all(MYSQLI_ASSOC) : [];
                            foreach ($all_supervisors as $asup):
                                $is_inact = (strtolower((string)($asup['status'] ?? '')) === 'inactive');
                            ?>
                                <option value="<?= (int)$asup['id'] ?>"><?= htmlspecialchars($asup['username']) ?> (<?= htmlspecialchars($asup['email']) ?>)<?= $is_inact ? ' [Inactive]' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer shrink-0">
                        ➕ Assign
                    </button>
                </form>
                <div id="assignToYearError" class="hidden bg-red-50 border border-red-200 text-red-600 text-xs font-semibold px-4 py-2.5 rounded-xl mt-3"></div>
            </div>

            <!-- Supervisors List -->
            <div>
                <h4 class="text-xs font-black text-slate-500 uppercase tracking-wider mb-3">👨‍🏫 Assigned Supervisors</h4>
                <div id="yearSupervisorList" class="space-y-2"></div>
                <div id="yearSupervisorEmpty" class="hidden p-8 text-center">
                    <div class="w-14 h-14 rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center text-2xl mx-auto mb-3">👨‍🏫</div>
                    <p class="text-sm font-semibold text-slate-500">No supervisors assigned to this year</p>
                    <p class="text-xs text-slate-400 mt-1">Use the form above to assign supervisors.</p>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="px-6 py-3 border-t border-slate-100 bg-slate-50 flex justify-end shrink-0">
            <button onclick="closeYearDetailsModal()" class="px-4 py-2 bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-bold rounded-xl transition cursor-pointer">Close</button>
        </div>
    </div>
</div>

</body>
</html>
