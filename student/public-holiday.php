<?php
require_once __DIR__ . '/../config/week_helper.php';
require_once __DIR__ . '/../auth.php';

$user_id  = $_SESSION['user_id'];
$username = $_SESSION['username'];
$role     = $_SESSION['role'];

if ($role !== 'student') {
    header('Location: ../dashboard.php');
    exit;
}

$esc_uid = $conn->real_escape_string($user_id);

$student_name = '';
$student_roll = '';
$profile_pic  = '';

$_s = $conn->query("SELECT sp.full_name, sp.student_roll, sp.internship_start_date, sp.internship_end_date, u.profile_pic FROM student_profiles sp JOIN users u ON u.id = sp.user_id WHERE sp.user_id = {$esc_uid}");
if ($_s && $_s->num_rows > 0) {
    $_sd = $_s->fetch_assoc();
    $student_name = $_sd['full_name'] ?? $username;
    $student_roll = $_sd['student_roll'] ?? '';
    $profile_pic  = $_sd['profile_pic'] ?? '';
    $intern_start = $_sd['internship_start_date'] ?? null;
    $intern_end   = $_sd['internship_end_date'] ?? null;
} else {
    $student_name = $username;
    $intern_start = null;
    $intern_end   = null;
}

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

$progress_weeks_completed = 0;
$progress_total_weeks = count($weeks);
if (!empty($weeks)) {
    foreach ($weeks as $wn => $wr) {
        $esc_wk_s = $conn->real_escape_string($wr['start']);
        $esc_wk_e = $conn->real_escape_string($wr['end']);
        $wc_r = $conn->query("SELECT COUNT(*) FROM daily_logs WHERE internship_id = {$esc_uid} AND log_date BETWEEN '{$esc_wk_s}' AND '{$esc_wk_e}'");
        if ($wc_r && $wc_r->num_rows > 0 && (int) $wc_r->fetch_row()[0] > 0) {
            $progress_weeks_completed++;
        }
    }
}

require_once __DIR__ . '/../config/database.php';

// Fetch all holidays from database
$hol_stmt = $pdo->query("SELECT holiday_date, holiday_name, holiday_name_mm, note FROM holidays ORDER BY holiday_date ASC");
$db_holidays = $hol_stmt->fetchAll();
$holiday_json = json_encode($db_holidays);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Intern Period Calendar - InternReport</title>
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
                },
            }
        }
    }
    </script>
    <style>
        /* Sidebar */
        .glass-sidebar {
            background: #0f172a;
            border-right: 1px solid rgba(255,255,255,0.08);
        }
        .glass-sidebar nav { scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.15) transparent; }
        .glass-sidebar nav::-webkit-scrollbar { width: 4px; }
        .glass-sidebar nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.15); border-radius: 4px; }
        .nav-link { color: rgba(255,255,255,0.55); font-weight: 500; }
        .nav-link:hover { color: #fff; background: rgba(255,255,255,0.1); }
        .active-nav { background: #9333ea; color: #fff; font-weight: 600; box-shadow: 0 4px 12px rgba(147,51,234,0.3); }
        /* Calendar tooltip */
        .holiday-cell { position: relative; }
        .holiday-tooltip {
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.2s ease, visibility 0.2s ease, transform 0.2s ease;
            transform: translateY(4px);
            pointer-events: none;
        }
        .holiday-cell:hover .holiday-tooltip {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        /* Subtle pulse for today */
        @keyframes softPulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(99, 102, 241, 0.3); }
            50% { box-shadow: 0 0 0 4px rgba(99, 102, 241, 0); }
        }
        .today-ring { animation: softPulse 2s ease-in-out infinite; }
    </style>
</head>
<body class="bg-slate-100 min-h-screen font-sans antialiased">

<div class="flex h-screen overflow-hidden">

    <!-- ─── SIDEBAR ─── -->
    <aside class="w-56 glass-sidebar flex flex-col shrink-0">
        <div class="h-14 flex items-center px-5 border-b border-white/10">
            <span class="font-black text-white tracking-tight">InternReport</span>
        </div>
        <nav class="flex-1 min-h-0 py-4 space-y-1 px-3 overflow-y-auto">
            <a href="student-dashboard.php" class="nav-link active-nav flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg> Dashboard
            </a>
            <a href="notifications.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg> Notifications
            </a>
            <a href="log-history.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg> Log History
            </a>
            <a href="instructions.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253z"/></svg> Instructions
            </a>
            <a href="profile.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-subtitle leading-relaxed transition-colors duration-200">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg> Profile
            </a>
        </nav>
        <div class="p-3 border-t border-white/10">
            <a href="../logout.php" class="flex items-center gap-3 px-3 py-2.5 text-subtitle leading-relaxed font-semibold text-red-400 hover:text-red-300 hover:bg-white/10 rounded-lg transition-colors duration-200">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg> Logout
            </a>
        </div>
    </aside>

    <!-- ─── MAIN CONTENT ─── -->
    <div class="flex-1 flex flex-col min-h-0">

        <!-- Top Bar -->
        <?php $pageTitle = 'Intern Period Calendar'; $show_back_link = true; include '../includes/student-topbar.php'; ?>

        <!-- Page Content (scrollable) -->
        <main class="flex-1 overflow-y-auto p-6">

            <div class="max-w-5xl mx-auto">

                <!-- Page Header -->
                <div class="mb-6">
                    <h1 class="text-lg font-black text-slate-800 uppercase tracking-wider flex items-center gap-2">
                        <svg class="w-5 h-5 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg> Intern Period Calendar
                    </h1>
                    <p class="text-xs text-slate-400 mt-1">View public holidays during your internship period</p>
                </div>

                <!-- Calendar Card -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">

                    <!-- Header: Month Navigation -->
                    <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div>
                                <h2 class="text-sm font-black text-slate-800 uppercase tracking-wider" id="calendar-title">July 2026</h2>
                                <p class="text-label text-slate-400 mt-0.5">Public holidays during your internship</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <button onclick="prevMonth()" class="w-8 h-8 rounded-lg bg-slate-50 hover:bg-slate-100 border border-slate-200 flex items-center justify-center text-slate-500 text-xs font-bold transition cursor-pointer">
                                ‹
                            </button>
                            <button onclick="goToday()" class="px-3 py-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-600 text-label font-bold rounded-lg transition cursor-pointer uppercase tracking-wider">
                                Today
                            </button>
                            <button onclick="nextMonth()" class="w-8 h-8 rounded-lg bg-slate-50 hover:bg-slate-100 border border-slate-200 flex items-center justify-center text-slate-500 text-xs font-bold transition cursor-pointer">
                                ›
                            </button>
                        </div>
                    </div>

                    <!-- Day-of-Week Headers -->
                    <div class="grid grid-cols-7 border-b border-slate-100">
                        <div class="px-2 py-2.5 text-center text-label font-black text-slate-400 uppercase tracking-widest">Sun</div>
                        <div class="px-2 py-2.5 text-center text-label font-black text-slate-400 uppercase tracking-widest">Mon</div>
                        <div class="px-2 py-2.5 text-center text-label font-black text-slate-400 uppercase tracking-widest">Tue</div>
                        <div class="px-2 py-2.5 text-center text-label font-black text-slate-400 uppercase tracking-widest">Wed</div>
                        <div class="px-2 py-2.5 text-center text-label font-black text-slate-400 uppercase tracking-widest">Thu</div>
                        <div class="px-2 py-2.5 text-center text-label font-black text-slate-400 uppercase tracking-widest">Fri</div>
                        <div class="px-2 py-2.5 text-center text-label font-black text-slate-400 uppercase tracking-widest">Sat</div>
                    </div>

                    <!-- Calendar Grid -->
                    <div id="calendar-grid" class="grid grid-cols-7">
                        <!-- Cells rendered by JS -->
                    </div>

                    <!-- Footer Legend -->
                    <div class="px-5 py-3 border-t border-slate-100 flex flex-wrap items-center gap-4">
                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded bg-indigo-100 border-2 border-indigo-400 ring-2 ring-indigo-100"></span>
                            <span class="text-label font-semibold text-slate-500">Today</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded bg-rose-100 border border-rose-200"></span>
                            <span class="text-label font-semibold text-slate-500">Holiday</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded bg-slate-50 border border-slate-200"></span>
                            <span class="text-label font-semibold text-slate-500">Regular Day</span>
                        </div>
                    </div>

                </div>

                <!-- Color Legend Card -->
<div class="mt-6 bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="px-5 py-3 border-b border-slate-100">
        <h2 class="text-sm font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
            <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/></svg> Holiday Color Legend
        </h2>
    </div>
    <div id="color-legend" class="px-5 py-3 flex flex-wrap gap-2">
        <!-- Rendered by JS -->
    </div>
</div>

<!-- Upcoming Holidays Card -->
                <div class="mt-6 bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between gap-3">
                        <h2 class="text-sm font-black text-slate-700 uppercase tracking-wider flex items-center gap-2 shrink-0">
                            <svg class="w-4 h-4 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg> Upcoming Holidays
                        </h2>
                        <div class="flex items-center gap-2">
                            <div class="relative">
                                <svg class="w-4 h-4 text-slate-400 absolute left-2.5 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                <input type="text" id="holiday-search" placeholder="Search holidays..." oninput="renderUpcoming()" class="w-44 bg-slate-50 border border-slate-200 rounded-lg pl-7 pr-2 py-1.5 text-caption text-slate-700 placeholder:text-slate-400 focus:outline-none focus:border-indigo-400 focus:bg-white transition">
                            </div>
                            <span class="text-label text-slate-400 font-semibold shrink-0" id="holiday-count"></span>
                        </div>
                    </div>
                    <div id="upcoming-list" class="divide-y divide-slate-100">
                        <!-- Rendered by JS -->
                    </div>
                </div>

                <!-- Info Note -->
                <div class="mt-6 bg-slate-50 border border-slate-200 rounded-2xl p-4">
                    <h3 class="text-xs font-bold text-slate-700 mb-2">Notes</h3>
                    <ul class="text-caption text-slate-500 space-y-1">
                        <li>• These are public holidays set by the administrator during your internship period.</li>
                        <li>• Holiday dates will be automatically marked as <strong>"Leave"</strong> in your daily logs.</li>
                        <li>• Hover over a holiday on the calendar to see its details.</li>
                    </ul>
                </div>

            </div>

        </main>

    </div>
</div>

<script>
// ── Holiday Data (from Admin via Database) ──
var holidays = {};
var dbHolidays = <?= $holiday_json ?>;
var defaultColors = ['#ef4444','#3b82f6','#22c55e','#f97316','#8b5cf6','#eab308','#ec4899','#f43f5e'];
dbHolidays.forEach(function(row, i) {
    holidays[row.holiday_date] = {
        name: row.holiday_name_mm || row.holiday_name,
        note: row.note || '',
        color: defaultColors[i % defaultColors.length]
    };
});

// ── State ──
var today = new Date();
var currentYear = today.getFullYear();
var currentMonth = today.getMonth();

var monthNames = [
    "January", "February", "March", "April", "May", "June",
    "July", "August", "September", "October", "November", "December"
];

// ── Helpers ──
function toKey(y, m, d) {
    return y + "-" + String(m + 1).padStart(2, "0") + "-" + String(d).padStart(2, "0");
}
function isHoliday(y, m, d) { return holidays.hasOwnProperty(toKey(y, m, d)); }
function holidayName(y, m, d) { var h = holidays[toKey(y, m, d)]; return h ? h.name : ""; }
function holidayNote(y, m, d) { var h = holidays[toKey(y, m, d)]; return h ? (h.note || "") : ""; }
function holidayColor(y, m, d) { var h = holidays[toKey(y, m, d)]; return h ? (h.color || "#f43f5e") : "#f43f5e"; }
function hexToRgba(hex, a) {
    var r = parseInt(hex.slice(1,3),16), g = parseInt(hex.slice(3,5),16), b = parseInt(hex.slice(5,7),16);
    return "rgba("+r+","+g+","+b+","+a+")";
}
function isToday(y, m, d) {
    return y === today.getFullYear() && m === today.getMonth() && d === today.getDate();
}

// ── Render Calendar ──
function render() {
    document.getElementById("calendar-title").textContent = monthNames[currentMonth] + " " + currentYear;
    var grid = document.getElementById("calendar-grid");
    grid.innerHTML = "";
    var firstDay = new Date(currentYear, currentMonth, 1).getDay();
    var daysInMonth = new Date(currentYear, currentMonth + 1, 0).getDate();

    for (var i = 0; i < firstDay; i++) {
        var empty = document.createElement("div");
        empty.className = "min-h-[3.5rem] sm:min-h-[4.5rem] border-b border-r border-slate-50 bg-slate-50/30";
        grid.appendChild(empty);
    }

    for (var d = 1; d <= daysInMonth; d++) {
        var hol = isHoliday(currentYear, currentMonth, d);
        var td = isToday(currentYear, currentMonth, d);
        var name = holidayName(currentYear, currentMonth, d);
        var note = holidayNote(currentYear, currentMonth, d);
        var hColor = hol ? holidayColor(currentYear, currentMonth, d) : "#f43f5e";
        var dateKey = toKey(currentYear, currentMonth, d);

        var cell = document.createElement("div");
        cell.className = "holiday-cell min-h-[3.5rem] sm:min-h-[4.5rem] border-b border-r border-slate-50 flex flex-col items-center justify-start pt-1.5 transition-colors " +
            (hol ? "hover:brightness-95 cursor-pointer" : "bg-white hover:bg-slate-50");

        if (hol) {
            cell.style.backgroundColor = hexToRgba(hColor, 0.08);
            cell.setAttribute("onmouseenter", "this.style.backgroundColor='" + hexToRgba(hColor, 0.15) + "'");
            cell.setAttribute("onmouseleave", "this.style.backgroundColor='" + hexToRgba(hColor, 0.08) + "'");
        }

        var html = "";
        if (td && hol) {
            html += '<span class="w-6 h-6 rounded-full bg-indigo-500 text-white text-caption font-black flex items-center justify-center today-ring">' + d + '</span>';
        } else if (td) {
            html += '<span class="w-6 h-6 rounded-full bg-indigo-500 text-white text-caption font-black flex items-center justify-center today-ring">' + d + '</span>';
        } else if (hol) {
            html += '<span class="w-6 h-6 rounded-full text-white text-caption font-black flex items-center justify-center" style="background:' + hColor + '">' + d + '</span>';
        } else {
            html += '<span class="text-caption font-semibold text-slate-600">' + d + '</span>';
        }

        if (hol) {
            html += '<span class="mt-0.5 px-1 py-0.5 text-micro font-black rounded uppercase tracking-wider leading-none text-center max-w-full truncate hidden sm:block" style="background:' + hexToRgba(hColor, 0.15) + ';color:' + hColor + '">' +
                (name.length > 12 ? name.substring(0, 10) + "…" : name) + '</span>';
            html += '<span class="mt-0.5 text-micro font-bold sm:hidden" style="color:' + hColor + '">●</span>';
        }

        if (hol) {
            html += '<div class="holiday-tooltip absolute bottom-full left-1/2 -translate-x-1/2 mb-2 z-50">';
            html += '  <div class="bg-slate-900 text-white text-label font-semibold rounded-lg px-3 py-2 shadow-lg whitespace-nowrap">';
            html += '    <p class="font-black mb-0.5" style="color:' + hColor + '">' + name + '</p>';
            html += '    <p class="text-slate-300">' + monthNames[currentMonth] + ' ' + d + ', ' + currentYear + '</p>';
            if (note) html += '    <p class="text-slate-400 mt-0.5 italic">' + note + '</p>';
            html += '  </div>';
            html += '  <div class="w-2 h-2 bg-slate-900 rotate-45 mx-auto -mt-1"></div>';
            html += '</div>';
        }

        cell.innerHTML = html;
        grid.appendChild(cell);
    }

    var totalCells = firstDay + daysInMonth;
    var remainder = totalCells % 7;
    if (remainder !== 0) {
        for (var j = 0; j < 7 - remainder; j++) {
            var trailing = document.createElement("div");
            trailing.className = "min-h-[3.5rem] sm:min-h-[4.5rem] border-b border-r border-slate-50 bg-slate-50/30";
            grid.appendChild(trailing);
        }
    }
    renderUpcoming();
    renderLegend();
}

// ── Render Upcoming Holidays List ──
function renderUpcoming() {
    var list = document.getElementById("upcoming-list");
    var countEl = document.getElementById("holiday-count");
    var searchEl = document.getElementById("holiday-search");
    var query = searchEl ? searchEl.value.trim().toLowerCase() : "";
    var todayKey = toKey(today.getFullYear(), today.getMonth(), today.getDate());
    var keys = Object.keys(holidays).sort();
    var upcoming = [];
    for (var i = 0; i < keys.length; i++) { if (keys[i] >= todayKey) upcoming.push(keys[i]); }

    // Filter by search query
    if (query) {
        var filtered = [];
        for (var j = 0; j < upcoming.length; j++) {
            var h = holidays[upcoming[j]];
            if (h.name.toLowerCase().indexOf(query) !== -1 || (h.note && h.note.toLowerCase().indexOf(query) !== -1)) {
                filtered.push(upcoming[j]);
            }
        }
        upcoming = filtered;
    }

    countEl.textContent = upcoming.length + " found";

    if (upcoming.length === 0) {
        var emptyMsg = query ? 'No holidays matching "' + query + '"' : 'No upcoming holidays.';
        list.innerHTML = '<div class="px-5 py-8 text-center text-xs text-slate-400">' + emptyMsg + '</div>';
        return;
    }

    var html = "";
    var display = upcoming.length > 5 && !query ? upcoming.slice(0, 5) : upcoming;
    for (var k = 0; k < display.length; k++) {
        var parts = display[k].split("-");
        var yr = parseInt(parts[0]), mo = parseInt(parts[1]) - 1, dy = parseInt(parts[2]);
        var dayName = ["Sunday","Monday","Tuesday","Wednesday","Thursday","Friday","Saturday"][new Date(yr, mo, dy).getDay()];
        var isPast = display[k] < todayKey;
        var hData = holidays[display[k]];
        var hCol = hData.color || "#f43f5e";

        html += '<div class="px-4 py-3 flex items-center gap-3 ' + (isPast ? 'opacity-50' : '') + '">';
        html += '  <div class="w-10 h-10 rounded-xl flex flex-col items-center justify-center shrink-0" style="background:' + hexToRgba(hCol, 0.15) + ';color:' + hCol + '">';
        html += '    <span class="text-sm font-black leading-none">' + dy + '</span>';
        html += '    <span class="text-micro font-bold uppercase leading-none mt-0.5">' + monthNames[mo].substring(0, 3) + '</span>';
        html += '  </div>';
        html += '  <div class="flex-1 min-w-0">';
        html += '    <p class="text-xs font-bold text-slate-700 truncate">' + hData.name + '</p>';
        html += '    <p class="text-label text-slate-400">' + dayName + ', ' + monthNames[mo] + ' ' + dy + ', ' + yr + '</p>';
        html += '  </div>';
        html += '</div>';
    }
    if (!query && upcoming.length > 5) {
        html += '<div class="px-4 py-2.5 bg-slate-50 text-center">';
        html += '  <span class="text-label font-bold text-slate-400">+' + (upcoming.length - 5) + ' more holidays this year</span>';
        html += '</div>';
    }
    list.innerHTML = html;
}

// ── Navigation ──
// ---- Render Color Legend ----
function renderLegend() {
    var el = document.getElementById("color-legend");
    if (!el) return;
    var colorMap = {};
    for (var key in holidays) {
        var h = holidays[key];
        var c = h.color || "#f43f5e";
        if (!colorMap[c]) colorMap[c] = [];
        if (colorMap[c].indexOf(h.name) === -1) colorMap[c].push(h.name);
    }
    var out = "";
    for (var col in colorMap) {
        var names = colorMap[col];
        var label = names.length === 1 ? names[0] : names[0] + " (" + names.length + ")";
        var tip = names.join(", ");
        out += "<div class=\"flex items-center gap-1.5 px-2 py-1 bg-slate-50 rounded-lg border border-slate-100 cursor-default\" title=\"" + tip + "\">";
        out += "<span class=\"w-2.5 h-2.5 rounded-full shrink-0\" style=\"background:" + col + "\"></span>";
        out += "<span class=\"text-label font-semibold text-slate-600 truncate max-w-[120px]\" title=\"" + tip + "\">" + label + "</span>";
        out += "</div>";
    }
    if (!out) out = "<p class=\"text-label text-slate-400\">No holidays configured.</p>";
    el.innerHTML = out;
}
function prevMonth() { currentMonth--; if (currentMonth < 0) { currentMonth = 11; currentYear--; } render(); }
function nextMonth() { currentMonth++; if (currentMonth > 11) { currentMonth = 0; currentYear++; } render(); }
function goToday() { currentYear = today.getFullYear(); currentMonth = today.getMonth(); render(); }

// ── Init ──
render();
</script>

</body>
</html>
