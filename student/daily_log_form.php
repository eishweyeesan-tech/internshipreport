<?php
require_once __DIR__ . '/../config/week_helper.php';
require_once __DIR__ . '/../auth.php';

$user_id       = $_SESSION['user_id'];
$internship_id = $user_id;

// ══════════════════════════════════════════════════════════════════════
// FETCH INTERNSHIP DATE RANGE
// ══════════════════════════════════════════════════════════════════════
$esc_uid = $conn->real_escape_string($user_id);
$profile_r = $conn->query("SELECT internship_start_date, internship_end_date FROM student_profiles WHERE user_id = {$esc_uid}");
$profile_row = $profile_r ? $profile_r->fetch_assoc() : null;
$intern_start = $profile_row['internship_start_date'] ?? null;
$intern_end   = $profile_row['internship_end_date'] ?? null;

// ══════════════════════════════════════════════════════════════════════
// FETCH PUBLIC HOLIDAYS (Myanmar Calendar)
// ══════════════════════════════════════════════════════════════════════
$all_holidays = [];
$hol_r = $conn->query("SELECT holiday_date, holiday_name FROM holidays ORDER BY holiday_date ASC");
if ($hol_r) { while ($row = $hol_r->fetch_assoc()) { $all_holidays[] = $row; } }
$holiday_dates = [];
foreach ($all_holidays as $hl) { $holiday_dates[$hl['holiday_date']] = $hl['holiday_name']; }
$holiday_date_list = array_keys($holiday_dates);

// ══════════════════════════════════════════════════════════════════════
// FETCH EXISTING LOG DATES (for duplicate prevention)
// ══════════════════════════════════════════════════════════════════════
$esc_iid = $conn->real_escape_string($internship_id);
$log_dates_r = $conn->query("SELECT log_date FROM daily_logs WHERE internship_id = {$esc_iid}");
$existing_logs = [];
if ($log_dates_r) {
    while ($row = $log_dates_r->fetch_assoc()) {
        $existing_logs[] = $row['log_date'];
    }
}

// ══════════════════════════════════════════════════════════════════════
// BUILD WEEK RANGES
// ══════════════════════════════════════════════════════════════════════
$weeks = [];
if ($intern_start) {
    $w = 1;
    while (true) {
        $range = getWeekRange($intern_start, $w);
        if (!$range) break;
        if ($intern_end && $range['start'] > $intern_end) break;
        $weeks[$w] = $range;
        $w++;
    }
}

// ══════════════════════════════════════════════════════════════════════
// HANDLE FORM SUBMISSION
// ══════════════════════════════════════════════════════════════════════
$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_log'])) {
    $selected_week = (int) ($_POST['selected_week'] ?? 0);
    $log_date      = trim($_POST['log_date'] ?? '');

    if ($selected_week < 1 || $selected_week > count($weeks)) {
        $error = 'Invalid week selection.';
    } elseif (empty($log_date)) {
        $error = 'Please select a date.';
    } else {
        // ── Server-side: date must fall within the selected week ──
        $week_range = $weeks[$selected_week] ?? null;
        if (!$week_range || $log_date < $week_range['start'] || $log_date > $week_range['end']) {
            $error = "Date must be between {$week_range['start']} and {$week_range['end']} (Week {$selected_week}).";
        }
        // ── All basic validations passed → check for duplicate then insert ──
        else {
            // ── Step 1: Server-side duplicate check (SELECT before INSERT) ──
            $esc_log = $conn->real_escape_string($log_date);
            $dup_r = $conn->query("SELECT id FROM daily_logs WHERE internship_id = {$esc_iid} AND log_date = '{$esc_log}' LIMIT 1");
            if ($dup_r && $dup_r->num_rows > 0) {
                $error = "duplicate_log";
            } else {
                // ── Step 2: No duplicate → safe to INSERT ─────────────
                $attendance_status  = trim($_POST['attendance_status'] ?? 'present');
                $reason_for_absence = trim($_POST['reason_for_absence'] ?? '');

                // Auto-set to "leave" for public holidays
                if (isset($holiday_dates[$log_date])) {
                    $attendance_status = 'leave';
                    $reason_for_absence = 'Public Holiday - ' . $holiday_dates[$log_date];
                }

                $intended_task      = trim($_POST['intended_task'] ?? '');
                $task_detail        = trim($_POST['task_detail'] ?? '');
                $actual_task        = trim($_POST['actual_task'] ?? '');
                $tools_used         = trim($_POST['tools_used'] ?? '');
                $knowledge_gained   = trim($_POST['knowledge_gained'] ?? '');
                $start_time         = trim($_POST['start_time'] ?? '09:00');
                $end_time           = trim($_POST['end_time'] ?? '17:00');
                $hours_worked       = trim($_POST['hours_worked'] ?? '08:00');

                if ($attendance_status === 'absent') {
                    $intended_task    = $reason_for_absence ?: 'Absent';
                    $task_detail      = 'N/A - Absent';
                    $actual_task      = 'N/A - Absent';
                    $tools_used       = 'N/A - Absent';
                    $knowledge_gained = 'N/A - Absent';
                    $hours_worked     = '00:00';
                }

                $esc_att  = $conn->real_escape_string($attendance_status);
                $esc_rfa  = $conn->real_escape_string($reason_for_absence);
                $esc_it   = $conn->real_escape_string($intended_task);
                $esc_td   = $conn->real_escape_string($task_detail);
                $esc_at   = $conn->real_escape_string($actual_task);
                $esc_tu   = $conn->real_escape_string($tools_used);
                $esc_kg   = $conn->real_escape_string($knowledge_gained);
                $esc_hw   = $conn->real_escape_string($hours_worked);
                $esc_ld   = $conn->real_escape_string($log_date);

                $conn->query("INSERT INTO daily_logs
                    (internship_id, log_date, attendance_status, reason_for_absence,
                     task_title, task_detail, tasks_performed, tools_used, learnt_skills, calculated_duration)
                    VALUES ({$esc_iid}, '{$esc_ld}', '{$esc_att}', '{$esc_rfa}',
                            '{$esc_it}', '{$esc_td}', '{$esc_at}', '{$esc_tu}', '{$esc_kg}', '{$esc_hw}')");

                $success = "Daily log for {$log_date} saved successfully.";
                $existing_logs[] = $log_date;
                sort($existing_logs);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Log Form – InternReport</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Flatpickr CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <!-- Flatpickr Material Blue theme (matches Tailwind blue palette) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/material_blue.css">

    <style>
        /* ── Flatpickr Overrides ─────────────────────────────────────── */
        .flatpickr-calendar {
            border-radius: 0.75rem !important;
            border: 1px solid #e2e8f0 !important;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,.1), 0 8px 10px -6px rgba(0,0,0,.1) !important;
            font-family: inherit !important;
            overflow: hidden;
        }
        .flatpickr-months .flatpickr-month {
            background: linear-gradient(to right, #3b82f6, #6366f1);
            color: #fff;
            border-radius: 0;
        }
        .flatpickr-current-month .flatpickr-monthDropdown-months,
        .flatpickr-current-month input.cur-year {
            color: #fff;
            font-weight: 700;
        }
        span.flatpickr-weekday {
            color: #64748b;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .flatpickr-day {
            border-radius: 0.5rem !important;
            font-size: 12px;
            font-weight: 500;
            margin: 2px !important;
            width: 34px;
            height: 34px;
            line-height: 34px;
        }
        .flatpickr-day:hover:not(.flatpickr-disabled):not(.selected) {
            background: #eff6ff;
            border-color: #eff6ff;
        }
        .flatpickr-day.selected,
        .flatpickr-day.selected:hover {
            background: #3b82f6 !important;
            border-color: #3b82f6 !important;
            box-shadow: 0 2px 8px rgba(59,130,246,.35);
        }
        .flatpickr-day.today {
            border-color: #3b82f6 !important;
        }
        .flatpickr-day.today:hover {
            background: #eff6ff !important;
        }

        /* Disabled dates: already-submitted */
        .flatpickr-day.flatpickr-disabled {
            color: #cbd5e1 !important;
            background: #f8fafc !important;
            text-decoration: line-through;
            cursor: not-allowed !important;
            opacity: 0.55;
        }
        .flatpickr-day.flatpickr-disabled:hover {
            background: #f8fafc !important;
            border-color: transparent !important;
        }

        /* Already-submitted dates: red indicator dot */
        .fp-log-exists::after {
            content: '';
            position: absolute;
            bottom: 3px;
            left: 50%;
            transform: translateX(-50%);
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: #ef4444;
        }

        /* Holiday dates: red tint */
        .flatpickr-day.fp-holiday {
            background: #fef2f2 !important;
            border-color: #fecaca !important;
            color: #dc2626 !important;
            font-weight: 700;
        }

        /* Navigation arrows */
        .flatpickr-prev-month,
        .flatpickr-next-month {
            fill: #fff !important;
            stroke: #fff !important;
        }
        .flatpickr-prev-month:hover,
        .flatpickr-next-month:hover {
            fill: #e0e7ff !important;
            stroke: #e0e7ff !important;
        }

        /* Input field wrapper */
        #logDateWrap {
            position: relative;
        }
        #logDateWrap .fp-input {
            padding-right: 2.5rem;
        }
    </style>
</head>
<body class="bg-slate-50 font-sans antialiased min-h-screen p-6">

<div class="max-w-2xl mx-auto space-y-6">

    <!-- Header -->
    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-6">
        <h1 class="text-lg font-black text-slate-800 mb-1">Daily Log Sheet</h1>
        <p class="text-xs text-slate-400 leading-relaxed">
            Select a <strong class="text-slate-600">Week</strong> first, then pick a valid <strong class="text-slate-600">Date</strong>.
            Weekends, public holidays, and previously submitted dates are greyed out.
        </p>
    </div>

    <!-- Alert Messages -->
    <?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 rounded-2xl p-4 flex items-start gap-3">
        <div class="w-8 h-8 rounded-full bg-red-100 text-red-500 flex items-center justify-center text-sm shrink-0">✕</div>
        <div>
            <h3 class="text-xs font-bold text-red-700">Validation Error</h3>
            <p class="text-xs text-red-600 mt-0.5"><?= htmlspecialchars($error) ?></p>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="bg-emerald-50 border border-emerald-200 rounded-2xl p-4 flex items-start gap-3">
        <div class="w-8 h-8 rounded-full bg-emerald-100 text-emerald-500 flex items-center justify-center text-sm shrink-0">✓</div>
        <div>
            <h3 class="text-xs font-bold text-emerald-700">Success</h3>
            <p class="text-xs text-emerald-600 mt-0.5"><?= htmlspecialchars($success) ?></p>
        </div>
    </div>
    <?php endif; ?>

    <!-- ════════════════════════════════════════════════════════════════
         DAILY LOG FORM
         ════════════════════════════════════════════════════════════════ -->
    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-6">
        <h2 class="text-sm font-black text-slate-800 uppercase tracking-wider border-b border-slate-100 pb-3 mb-5 flex items-center gap-2">
            <span class="p-1 bg-blue-50 text-blue-600 rounded">📝</span> New Log Entry
        </h2>

        <form method="POST" id="logForm" class="space-y-5" onsubmit="return validateSubmit(event)">

            <!-- ── Week Select ── -->
            <div>
                <label class="block text-sm font-bold text-slate-500 mb-1">📆 Select Week</label>
                <select name="selected_week" id="weekSelect" required
                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800
                           focus:outline-none focus:border-blue-500 focus:bg-white transition cursor-pointer">
                    <option value="">— Choose a week —</option>
                    <?php foreach ($weeks as $wn => $wr): ?>
                    <option value="<?= $wn ?>">
                        Week <?= $wn ?> (<?= (new DateTime($wr['start']))->format('d M') ?> – <?= (new DateTime($wr['end']))->format('d M Y') ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
                <p id="weekHint" class="text-sm text-slate-400 mt-1 hidden"></p>
            </div>

            <!-- ── Date Picker (Flatpickr — disabled until week selected) ── -->
            <div>
                <label class="block text-sm font-bold text-slate-500 mb-1">📅 Log Date</label>
                <div id="logDateWrap" class="relative">
                    <input type="text" name="log_date" id="logDate" required readonly disabled
                        placeholder="Select a week first…"
                        class="w-full bg-slate-100 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800
                               focus:outline-none focus:border-blue-500 focus:bg-white transition
                               disabled:opacity-50 disabled:cursor-not-allowed">
                </div>
                <p id="dateHint" class="text-sm text-slate-400 mt-1">Select a week first to open the calendar.</p>
            </div>

            <!-- ── Attendance Status ── -->
            <div>
                <label class="block text-sm font-bold text-slate-500 mb-2">✅ Attendance Status</label>
                <div class="flex items-center gap-4">
                    <label class="flex items-center gap-1.5 px-3 py-1.5 bg-emerald-50 border border-emerald-200 rounded-lg cursor-pointer hover:bg-emerald-100 transition">
                        <input type="radio" name="attendance_status" value="present" checked onchange="toggleAttendance()" class="accent-emerald-600">
                        <span class="text-xs font-bold text-emerald-700">Present</span>
                    </label>
                    <label class="flex items-center gap-1.5 px-3 py-1.5 bg-red-50 border border-red-200 rounded-lg cursor-pointer hover:bg-red-100 transition">
                        <input type="radio" name="attendance_status" value="absent" onchange="toggleAttendance()" class="accent-red-600">
                        <span class="text-xs font-bold text-red-700">Absent</span>
                    </label>
                </div>
            </div>

            <!-- ════ PRESENT FIELDS ════ -->
            <div id="present-fields" class="space-y-4">
                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-bold text-slate-500 mb-1">⏱️ Start Time</label>
                        <input type="time" name="start_time" id="start_time" value="09:00" onchange="calcHours()"
                            class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-mono text-blue-600 focus:outline-none focus:border-blue-500 focus:bg-white transition">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-slate-500 mb-1">⏱️ End Time</label>
                        <input type="time" name="end_time" id="end_time" value="17:00" onchange="calcHours()"
                            class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-mono text-blue-600 focus:outline-none focus:border-blue-500 focus:bg-white transition">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-slate-500 mb-1">⏳ Duration</label>
                        <input type="text" id="hours_display" value="08:00" readonly
                            class="w-full bg-slate-100 border border-slate-200 rounded-xl px-3 py-2 text-xs font-mono text-blue-700 font-bold cursor-default">
                        <input type="hidden" name="hours_worked" id="hours_worked" value="08:00">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-500 mb-1">💡 Intended Task</label>
                    <input type="text" name="intended_task" placeholder="e.g. UI Design & API Integration"
                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 focus:bg-white transition">
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-500 mb-1">📋 Task Detail</label>
                    <textarea name="task_detail" rows="2" placeholder="Describe the planned tasks…"
                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-700 focus:outline-none focus:border-blue-500 focus:bg-white transition resize-none"></textarea>
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-500 mb-1">✅ Actual Task Performed</label>
                    <textarea name="actual_task" rows="2" placeholder="What you actually accomplished…"
                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-700 focus:outline-none focus:border-blue-500 focus:bg-white transition resize-none"></textarea>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-bold text-slate-500 mb-1">🛠️ Tools / Tech Used</label>
                        <input type="text" name="tools_used" placeholder="PHP, TailwindCSS, MySQL…"
                            class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-mono text-emerald-600 focus:outline-none focus:border-blue-500 focus:bg-white transition">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-slate-500 mb-1">🧠 Knowledge Gained</label>
                        <input type="text" name="knowledge_gained" placeholder="Database optimization, REST APIs…"
                            class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-700 focus:outline-none focus:border-blue-500 focus:bg-white transition">
                    </div>
                </div>
            </div>

            <!-- ════ ABSENT FIELDS ════ -->
            <div id="absent-fields" class="hidden space-y-4">
                <div>
                    <label class="block text-sm font-bold text-slate-500 mb-1">📝 Reason for Absence</label>
                    <textarea name="reason_for_absence" rows="2" placeholder="State your reason…"
                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-700 focus:outline-none focus:border-blue-500 focus:bg-white transition resize-none"></textarea>
                </div>
            </div>

            <div class="flex justify-end">
                <button type="submit" name="add_log"
                    class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer">
                    💾 Save Daily Log
                </button>
            </div>
        </form>
    </div>

    <!-- ── Week Reference Table ── -->
    <?php if (!empty($weeks)): ?>
    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white">
            <h3 class="text-sm font-bold text-slate-700 uppercase tracking-wider">Week Reference (<?= count($weeks) ?> weeks)</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50/80 text-slate-500 font-semibold uppercase tracking-wider text-sm">
                        <th class="px-5 py-3 text-left">Week</th>
                        <th class="px-5 py-3 text-left">Start</th>
                        <th class="px-5 py-3 text-left">End</th>
                        <th class="px-5 py-3 text-left">Logs</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($weeks as $wn => $wr): ?>
                    <?php
                        $esc_ws = $conn->real_escape_string($wr['start']);
                        $esc_we = $conn->real_escape_string($wr['end']);
                        $cnt_r = $conn->query("SELECT COUNT(*) AS cnt FROM daily_logs WHERE internship_id = {$esc_iid} AND log_date BETWEEN '{$esc_ws}' AND '{$esc_we}'");
                        $cnt_row = $cnt_r ? $cnt_r->fetch_assoc() : null;
                        $log_count = $cnt_row ? (int) $cnt_row['cnt'] : 0;
                    ?>
                    <tr class="hover:bg-slate-50/50 transition-colors duration-150">
                        <td class="px-5 py-3 font-bold text-slate-700">
                            Week <?= $wn ?>
                            <?php if ($wn === 1): ?>
                            <span class="text-sm text-amber-600 bg-amber-50 px-1.5 py-0.5 rounded ml-1">partial</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-3 text-xs font-mono text-slate-600"><?= (new DateTime($wr['start']))->format('D, d M Y') ?></td>
                        <td class="px-5 py-3 text-xs font-mono text-slate-600"><?= (new DateTime($wr['end']))->format('D, d M Y') ?></td>
                        <td class="px-5 py-3">
                            <span class="text-xs font-bold <?= $log_count >= 5 ? 'text-emerald-600' : 'text-slate-500' ?>"><?= $log_count ?>/5</span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- Flatpickr JS -->
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<!-- ════════════════════════════════════════════════════════════════════
     JAVASCRIPT — Flatpickr + Week/Date Validation Engine
     ════════════════════════════════════════════════════════════════════ -->
<script>
(function () {
    // ── Data from PHP ────────────────────────────────────────────────
    var weekRanges   = <?= json_encode($weeks, JSON_HEX_TAG) ?>;
    var existingLogs = <?= json_encode($existing_logs, JSON_HEX_TAG) ?>;
    var holidayDates = <?= json_encode($holiday_date_list, JSON_HEX_TAG) ?>;
    var holidayNames = <?= json_encode($holiday_dates, JSON_HEX_TAG) ?>;
    var existingSet  = {};          // O(1) lookup for duplicate check
    existingLogs.forEach(function (d) { existingSet[d] = true; });
    var holidaySet = {};
    holidayDates.forEach(function (d) { holidaySet[d] = true; });

    var weekSelect = document.getElementById('weekSelect');
    var dateInput  = document.getElementById('logDate');
    var weekHint   = document.getElementById('weekHint');
    var dateHint   = document.getElementById('dateHint');

    // ── Build the disabled-dates config for Flatpickr ────────────────
    //  1. All dates the student already submitted
    //  2. All public holidays

    function buildDisableList() {
        return existingLogs.concat(holidayDates);
    }

    // ── Initialize Flatpickr ─────────────────────────────────────────
    var fp = flatpickr(dateInput, {
        dateFormat:    'Y-m-d',
        disableMobile: true,         // force custom UI on mobile
        weekStart:    0,             // Sunday-first calendar
        disable:      buildDisableList(),
        maxDate:      null,          // set on week change
        minDate:      null,          // set on week change
        clickOpens:   false,         // only open via week selection
        allowInput:   false,

        // ── Mark existing-log dates with a red dot, holidays with a flag ──
        onDayCreate: function (dObj, dStr, fp, dayElem) {
            var dateStr = fmtDate(dayElem.dateObj);
            if (existingSet[dateStr]) {
                dayElem.classList.add('fp-log-exists');
                dayElem.setAttribute('title', 'Already submitted — cannot select');
            } else if (holidaySet[dateStr]) {
                dayElem.style.background = '#fef2f2';
                dayElem.style.borderColor = '#fecaca';
                dayElem.style.color = '#dc2626';
                dayElem.style.fontWeight = '700';
                dayElem.setAttribute('title', 'Public Holiday - ' + holidayNames[dateStr]);
            }
        },

        // ── When a date is picked (should only be valid dates) ───────
        onChange: function (selectedDates, dateStr) {
            if (!dateStr) return;

            var d   = new Date(dateStr + 'T00:00:00');
            var day = dayName(d);

            // Belt-and-suspenders: duplicate check
            if (existingSet[dateStr]) {
                fp.clear();
                showToast('A log for ' + dateStr + ' already exists.', 'error');
                return;
            }

            // Public holiday: auto-set to leave
            if (holidaySet[dateStr]) {
                var leaveRadio = document.querySelector('input[name="attendance_status"][value="absent"]');
                if (leaveRadio) { leaveRadio.checked = true; toggleAttendance(); }
                var reasonField = document.querySelector('textarea[name="reason_for_absence"]');
                if (reasonField) { reasonField.value = 'Public Holiday - ' + holidayNames[dateStr]; }
                dateHint.textContent = '⚠️ ' + day + ', ' +
                    d.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }) +
                    ' — Public Holiday (' + holidayNames[dateStr] + '). Marked as Leave.';
                dateHint.className = 'text-sm text-amber-600 font-semibold mt-1';
                return;
            }

            dateHint.textContent = '✓ ' + day + ', ' +
                d.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }) +
                ' — ready to submit.';
            dateHint.className = 'text-sm text-emerald-600 font-semibold mt-1';
        }
    });

    // ── WEEK SELECT → Configure and open Flatpickr ───────────────────
    weekSelect.addEventListener('change', function () {
        var wn = parseInt(this.value);

        if (!wn || !weekRanges[wn]) {
            // No valid week → lock the date picker
            fp.set('clickOpens', false);
            fp.clear();
            fp.close();
            dateInput.disabled = true;
            dateInput.value = '';
            dateInput.placeholder = 'Select a week first…';
            dateInput.classList.add('bg-slate-100');
            dateInput.classList.remove('bg-white');
            weekHint.classList.add('hidden');
            dateHint.textContent = 'Select a week first to open the calendar.';
            dateHint.className = 'text-sm text-slate-400 mt-1';
            return;
        }

        var range = weekRanges[wn];

        // Enable the input
        dateInput.disabled = false;
        dateInput.classList.remove('bg-slate-100');
        dateInput.classList.add('bg-white');

        // Dynamically set the allowed date range + disabled dates
        fp.set('minDate', range.start);
        fp.set('maxDate', range.end);
        fp.set('disable', buildDisableList());
        fp.set('clickOpens', true);
        fp.clear();

        // Show week info
        var startD = new Date(range.start + 'T00:00:00');
        var endD   = new Date(range.end + 'T00:00:00');
        var totalDays = Math.round((endD - startD) / 86400000) + 1;

        weekHint.textContent = 'Week ' + wn + ': ' +
            dayName(startD) + ', ' + startD.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) +
            ' → ' +
            dayName(endD) + ', ' + endD.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        weekHint.className = 'text-sm text-blue-500 font-semibold mt-1';

        dateHint.textContent = 'Open the calendar — pick any weekday within this ' + totalDays + '-day window.';
        dateHint.className = 'text-sm text-slate-400 mt-1';

        // Auto-open the calendar for better UX
        fp.open();
    });

    // ── FORM SUBMIT → Final validation ───────────────────────────────
    window.validateSubmit = function (e) {
        var wn  = parseInt(weekSelect.value);
        var val = dateInput.value;

        if (!wn) {
            showToast('Please select a week first.', 'error');
            e.preventDefault();
            return false;
        }
        if (!val) {
            showToast('Please select a log date from the calendar.', 'error');
            e.preventDefault();
            return false;
        }

        var range = weekRanges[wn];

        // Week range check
        if (range && (val < range.start || val > range.end)) {
            showToast('The selected date is outside Week ' + wn + '. Allowed: ' + range.start + ' to ' + range.end, 'error');
            e.preventDefault();
            return false;
        }

        // Weekend check
        var dow = new Date(val + 'T00:00:00').getDay();
        if (dow === 0 || dow === 6) {
            showToast('Weekends are not allowed. Please select a weekday.', 'error');
            e.preventDefault();
            return false;
        }

        // Duplicate check
        if (existingSet[val]) {
            showToast('A log for this date already exists. Please choose another.', 'error');
            e.preventDefault();
            return false;
        }

        // Holiday check — auto-set leave but still allow submission
        if (holidaySet[val]) {
            var leaveRadio = document.querySelector('input[name="attendance_status"][value="absent"]');
            if (leaveRadio) { leaveRadio.checked = true; toggleAttendance(); }
            var reasonField = document.querySelector('textarea[name="reason_for_absence"]');
            if (reasonField && !reasonField.value) { reasonField.value = 'Public Holiday - ' + holidayNames[val]; }
        }

        return true;
    };

    // ── Helpers ──────────────────────────────────────────────────────
    function fmtDate(d) {
        var y = d.getFullYear();
        var m = String(d.getMonth() + 1).padStart(2, '0');
        var day = String(d.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + day;
    }

    function dayName(d) {
        return ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][d.getDay()];
    }

    // ── Duration calculator ──────────────────────────────────────────
    window.calcHours = function () {
        var start = document.getElementById('start_time').value;
        var end   = document.getElementById('end_time').value;
        if (!start || !end) return;
        var s = start.split(':'), e = end.split(':');
        var sm = parseInt(s[0]) * 60 + parseInt(s[1]);
        var em = parseInt(e[0]) * 60 + parseInt(e[1]);
        if (em < sm) em += 1440;
        var diff = em - sm;
        var h = Math.floor(diff / 60);
        var m = diff % 60;
        var pad = function (n) { return n < 10 ? '0' + n : n; };
        var result = pad(h) + ':' + pad(m);
        document.getElementById('hours_display').value = result;
        document.getElementById('hours_worked').value = result;
    };

    // ── Attendance toggle ────────────────────────────────────────────
    window.toggleAttendance = function () {
        var status  = document.querySelector('input[name="attendance_status"]:checked').value;
        var present = document.getElementById('present-fields');
        var absent  = document.getElementById('absent-fields');
        if (status === 'absent') {
            present.classList.add('hidden');
            absent.classList.remove('hidden');
        } else {
            present.classList.remove('hidden');
            absent.classList.add('hidden');
        }
    };
})();
</script>

<script>
function showToast(message, type) {
    var toast = document.createElement('div');
    var bgColor, icon;
    switch (type) {
        case 'success':
            bgColor = 'bg-emerald-600';
            icon = '✓';
            break;
        case 'error':
            bgColor = 'bg-red-600';
            icon = '✕';
            break;
        case 'warning':
            bgColor = 'bg-amber-500';
            icon = '⚠';
            break;
        default:
            bgColor = 'bg-slate-700';
            icon = 'ℹ';
    }
    toast.className = 'fixed bottom-6 right-6 z-[1000] ' + bgColor + ' text-white text-xs font-bold px-4 py-3 rounded-xl shadow-lg flex items-center gap-2 transition-all duration-300';
    toast.style.opacity = '0';
    toast.style.transform = 'translateY(10px)';
    toast.innerHTML = '<span class="text-base">' + icon + '</span> ' + message;
    document.body.appendChild(toast);
    requestAnimationFrame(function() {
        toast.style.opacity = '1';
        toast.style.transform = 'translateY(0)';
    });
    setTimeout(function() {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(10px)';
        setTimeout(function() { toast.remove(); }, 300);
    }, 3000);
}
</script>

<?php if ($error === 'duplicate_log'): ?>
<script>
(function () {
    showToast('A daily log for this date has already been submitted. Please select a different date.', 'error');
    // Clear the date input so the user must re-pick
    var fp = document.getElementById('logDate');
    if (fp) fp.value = '';
})();
</script>
<?php endif; ?>

</body>
</html>
