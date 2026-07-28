<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Public Holidays - InternReport</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Glass sidebar & header */
        .glass-sidebar {
            background: linear-gradient(180deg, rgba(30,27,75,0.95) 0%, rgba(55,48,107,0.95) 100%);
            backdrop-filter: blur(12px);
        }
        .glass-header {
            background: rgba(255,255,255,0.85);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(226,232,240,0.5);
        }
        .active-nav {
            background: rgba(255,255,255,0.12);
            color: white !important;
        }
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
        /* Color swatch selected state */
        .color-swatch.ring-2 {
            box-shadow: 0 0 0 2px white, 0 0 0 4px currentColor;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-slate-50 via-indigo-50/30 to-rose-50/20 min-h-screen font-sans antialiased">

<div class="flex h-screen overflow-hidden">

    <!-- ─── SIDEBAR ─── -->
    <aside class="w-56 glass-sidebar flex flex-col shrink-0">
        <div class="h-14 flex items-center px-5 border-b border-white/10">
            <span class="text-sm font-black text-white tracking-tight">📋 InternReport</span>
        </div>
        <nav class="flex-1 py-4 space-y-1 px-2">
            <a href="student-dashboard.php" class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-white/60 hover:text-white hover:bg-white/10 transition">
                <span>📝</span> Dashboard
            </a>
            <a href="student-dashboard.php?section=analytics" class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-white/60 hover:text-white hover:bg-white/10 transition">
                <span>📊</span> Analytics
            </a>
            <a href="log-history.php" class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-white/60 hover:text-white hover:bg-white/10 transition">
                <span>📜</span> Log History
            </a>
            <a href="public-holiday.php" class="nav-link active-nav flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-white/60 hover:text-white hover:bg-white/10 transition">
                <span>📅</span> Public Holidays
            </a>
            <a href="instructions.php" class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-white/60 hover:text-white hover:bg-white/10 transition">
                <span>📋</span> Instructions
            </a>
            <a href="profile.php" class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-semibold text-white/60 hover:text-white hover:bg-white/10 transition">
                <span>👤</span> Profile
            </a>
        </nav>
        <div class="p-3 border-t border-white/10">
            <a href="../logout.php" class="flex items-center gap-2 px-3 py-2 text-xs font-semibold text-red-400 hover:text-red-300 hover:bg-white/10 rounded-lg transition">🚪 Logout</a>
        </div>
    </aside>

    <!-- ─── MAIN CONTENT ─── -->
    <div class="flex-1 flex flex-col min-h-0">

        <!-- Top Bar -->
        <header class="h-14 glass-header flex items-center justify-between px-6 shrink-0 relative z-50">
            <div class="flex items-center gap-3">
                <span class="text-sm font-bold text-slate-700">📋 InternReport</span>
                <span class="w-px h-5 bg-slate-300/50"></span>
                <span class="text-xs font-semibold text-slate-500">Public Holidays</span>
            </div>
            <div class="flex items-center gap-3">
                <button onclick="openAddModal()" class="px-3 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-600 text-[10px] font-bold rounded-lg transition cursor-pointer uppercase tracking-wider border border-rose-200">
                    + Add Holiday
                </button>
                <a href="student-dashboard.php" class="text-xs font-semibold text-slate-500 hover:text-slate-700 transition">← Back to Dashboard</a>
            </div>
        </header>

        <!-- Page Content (scrollable) -->
        <main class="flex-1 overflow-y-auto p-6">

            <div class="max-w-5xl mx-auto">

                <!-- Page Header -->
                <div class="mb-6">
                    <h1 class="text-lg font-black text-slate-800 uppercase tracking-wider flex items-center gap-2">
                        <span class="p-1.5 bg-rose-50 text-rose-600 rounded-lg text-sm">📅</span> Public Holidays
                    </h1>
                    <p class="text-xs text-slate-400 mt-1">View and manage Myanmar public holidays for your internship period</p>
                </div>

                <!-- Calendar Card -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">

                    <!-- Header: Month Navigation -->
                    <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div>
                                <h2 class="text-sm font-black text-slate-800 uppercase tracking-wider" id="calendar-title">July 2026</h2>
                                <p class="text-[10px] text-slate-400 mt-0.5">Click a holiday to edit · Click + to add new</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <button onclick="prevMonth()" class="w-8 h-8 rounded-lg bg-slate-50 hover:bg-slate-100 border border-slate-200 flex items-center justify-center text-slate-500 text-xs font-bold transition cursor-pointer">
                                ‹
                            </button>
                            <button onclick="goToday()" class="px-3 py-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-600 text-[10px] font-bold rounded-lg transition cursor-pointer uppercase tracking-wider">
                                Today
                            </button>
                            <button onclick="nextMonth()" class="w-8 h-8 rounded-lg bg-slate-50 hover:bg-slate-100 border border-slate-200 flex items-center justify-center text-slate-500 text-xs font-bold transition cursor-pointer">
                                ›
                            </button>
                        </div>
                    </div>

                    <!-- Day-of-Week Headers -->
                    <div class="grid grid-cols-7 border-b border-slate-100">
                        <div class="px-2 py-2.5 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">Sun</div>
                        <div class="px-2 py-2.5 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">Mon</div>
                        <div class="px-2 py-2.5 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">Tue</div>
                        <div class="px-2 py-2.5 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">Wed</div>
                        <div class="px-2 py-2.5 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">Thu</div>
                        <div class="px-2 py-2.5 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">Fri</div>
                        <div class="px-2 py-2.5 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">Sat</div>
                    </div>

                    <!-- Calendar Grid -->
                    <div id="calendar-grid" class="grid grid-cols-7">
                        <!-- Cells rendered by JS -->
                    </div>

                    <!-- Footer Legend -->
                    <div class="px-5 py-3 border-t border-slate-100 flex flex-wrap items-center gap-4">
                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded bg-indigo-100 border-2 border-indigo-400 ring-2 ring-indigo-100"></span>
                            <span class="text-[10px] font-semibold text-slate-500">Today</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded bg-rose-100 border border-rose-200"></span>
                            <span class="text-[10px] font-semibold text-slate-500">Holiday</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded bg-slate-50 border border-slate-200"></span>
                            <span class="text-[10px] font-semibold text-slate-500">Regular Day</span>
                        </div>
                    </div>

                </div>

                <!-- Color Legend Card -->
<div class="mt-6 bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="px-5 py-3 border-b border-slate-100">
        <h2 class="text-sm font-black text-slate-700 uppercase tracking-wider flex items-center gap-2">
            <span class="p-1 bg-indigo-50 text-indigo-600 rounded">🎨</span> Holiday Color Legend
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
                            <span class="p-1 bg-rose-50 text-rose-600 rounded">🇲🇲</span> Upcoming Holidays
                        </h2>
                        <div class="flex items-center gap-2">
                            <div class="relative">
                                <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 text-[10px]">🔍</span>
                                <input type="text" id="holiday-search" placeholder="Search holidays..." oninput="renderUpcoming()" class="w-44 bg-slate-50 border border-slate-200 rounded-lg pl-7 pr-2 py-1.5 text-[11px] text-slate-700 placeholder:text-slate-400 focus:outline-none focus:border-indigo-400 focus:bg-white transition">
                            </div>
                            <span class="text-[10px] text-slate-400 font-semibold shrink-0" id="holiday-count"></span>
                        </div>
                    </div>
                    <div id="upcoming-list" class="divide-y divide-slate-100">
                        <!-- Rendered by JS -->
                    </div>
                </div>

                <!-- Info Note -->
                <div class="mt-6 bg-slate-50 border border-slate-200 rounded-2xl p-4">
                    <h3 class="text-xs font-bold text-slate-700 mb-2">ℹ️ Notes</h3>
                    <ul class="text-[11px] text-slate-500 space-y-1">
                        <li>• Click any holiday on the calendar to edit its name, note, or color.</li>
                        <li>• Use the <strong>+ Add Holiday</strong> button to add new holidays.</li>
                        <li>• Each holiday has a custom color dot — use the color picker to differentiate them.</li>
                        <li>• Holiday dates will be automatically marked as <strong>"Leave"</strong> in your daily logs.</li>
                    </ul>
                </div>

            </div>

        </main>

    </div>
</div>

<!-- ══════ HOLIDAY MODAL (Add / Edit) ══════ -->
<div id="holiday-modal" class="hidden fixed inset-0 z-[999] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeModal()"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md z-10 overflow-hidden">
        <!-- Modal Header -->
        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="p-1.5 bg-rose-50 text-rose-600 rounded-lg text-sm">📅</span>
                <h3 id="modal-title" class="text-sm font-black text-slate-800 uppercase tracking-wider">Add Public Holiday</h3>
            </div>
            <button onclick="closeModal()" class="w-7 h-7 rounded-lg bg-slate-100 hover:bg-slate-200 flex items-center justify-center text-slate-500 text-xs font-bold transition cursor-pointer">✕</button>
        </div>
        <!-- Modal Body -->
        <form id="holiday-form" class="p-5 space-y-4" onsubmit="return handleSaveHoliday(event)">
            <input type="hidden" id="modal-edit-key" value="">
            <div>
                <label class="block text-xs font-bold text-slate-600 mb-1">Holiday Date *</label>
                <input type="date" id="modal-holiday-date" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-rose-500 focus:bg-white transition">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-600 mb-1">Holiday Name *</label>
                <input type="text" id="modal-holiday-name" required placeholder="e.g. Thingyan Festival" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-rose-500 focus:bg-white transition">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-600 mb-1">Note <span class="text-slate-400 font-normal">(optional)</span></label>
                <input type="text" id="modal-holiday-note" placeholder="Optional note" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-rose-500 focus:bg-white transition">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-600 mb-1.5">Dot Color</label>
                <div class="flex items-center gap-2">
                    <div class="flex items-center gap-1.5 flex-wrap" id="color-presets">
                        <button type="button" onclick="pickColor('#f43f5e')" class="color-swatch w-6 h-6 rounded-full border-2 border-white shadow-sm ring-1 ring-slate-200 cursor-pointer hover:scale-110 transition" style="background:#f43f5e" data-color="#f43f5e"></button>
                        <button type="button" onclick="pickColor('#ef4444')" class="color-swatch w-6 h-6 rounded-full border-2 border-white shadow-sm ring-1 ring-slate-200 cursor-pointer hover:scale-110 transition" style="background:#ef4444" data-color="#ef4444"></button>
                        <button type="button" onclick="pickColor('#f97316')" class="color-swatch w-6 h-6 rounded-full border-2 border-white shadow-sm ring-1 ring-slate-200 cursor-pointer hover:scale-110 transition" style="background:#f97316" data-color="#f97316"></button>
                        <button type="button" onclick="pickColor('#eab308')" class="color-swatch w-6 h-6 rounded-full border-2 border-white shadow-sm ring-1 ring-slate-200 cursor-pointer hover:scale-110 transition" style="background:#eab308" data-color="#eab308"></button>
                        <button type="button" onclick="pickColor('#22c55e')" class="color-swatch w-6 h-6 rounded-full border-2 border-white shadow-sm ring-1 ring-slate-200 cursor-pointer hover:scale-110 transition" style="background:#22c55e" data-color="#22c55e"></button>
                        <button type="button" onclick="pickColor('#3b82f6')" class="color-swatch w-6 h-6 rounded-full border-2 border-white shadow-sm ring-1 ring-slate-200 cursor-pointer hover:scale-110 transition" style="background:#3b82f6" data-color="#3b82f6"></button>
                        <button type="button" onclick="pickColor('#8b5cf6')" class="color-swatch w-6 h-6 rounded-full border-2 border-white shadow-sm ring-1 ring-slate-200 cursor-pointer hover:scale-110 transition" style="background:#8b5cf6" data-color="#8b5cf6"></button>
                        <button type="button" onclick="pickColor('#ec4899')" class="color-swatch w-6 h-6 rounded-full border-2 border-white shadow-sm ring-1 ring-slate-200 cursor-pointer hover:scale-110 transition" style="background:#ec4899" data-color="#ec4899"></button>
                    </div>
                    <div class="relative">
                        <input type="color" id="modal-holiday-color" value="#f43f5e" onchange="pickColor(this.value)" class="w-6 h-6 rounded-full border-2 border-white shadow-sm ring-1 ring-slate-200 cursor-pointer p-0">
                    </div>
                    <span id="color-hex" class="text-[10px] font-mono text-slate-400">#f43f5e</span>
                </div>
            </div>
            <div id="modal-error" class="hidden bg-rose-50 border border-rose-200 rounded-xl px-3 py-2 text-xs text-rose-600 font-semibold"></div>
            <div class="flex items-center justify-between pt-2">
                <button type="button" id="modal-delete-btn" onclick="handleDeleteHoliday()" class="hidden px-4 py-2 bg-rose-50 hover:bg-rose-100 text-rose-600 text-xs font-bold rounded-xl border border-rose-200 transition cursor-pointer">
                    Delete
                </button>
                <div class="flex items-center gap-2 ml-auto">
                    <button type="button" onclick="closeModal()" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-bold rounded-xl transition cursor-pointer">Cancel</button>
                    <button type="submit" id="modal-submit-btn" class="px-5 py-2 bg-rose-500 hover:bg-rose-600 text-white text-xs font-bold rounded-xl shadow-sm transition cursor-pointer">Add Holiday</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
// ── Holiday Data (set by Admin) ──
var holidays = {
    "2026-01-04": { name: "Independence Day", note: "", color: "#ef4444" },
    "2026-01-12": { name: "National Garden Day", note: "", color: "#22c55e" },
    "2026-02-12": { name: "Union Day", note: "", color: "#3b82f6" },
    "2026-03-02": { name: "Peasants' Day", note: "", color: "#f97316" },
    "2026-03-13": { name: "Full Moon of Tabodwe", note: "", color: "#8b5cf6" },
    "2026-03-27": { name: "Armed Forces Day", note: "", color: "#ef4444" },
    "2026-04-13": { name: "Thingyan Festival (Water Festival)", note: "Official Water Festival holiday", color: "#3b82f6" },
    "2026-04-14": { name: "Thingyan Festival (Water Festival)", note: "", color: "#3b82f6" },
    "2026-04-15": { name: "Thingyan Festival (Water Festival)", note: "", color: "#3b82f6" },
    "2026-04-16": { name: "Thingyan Festival (Water Festival)", note: "", color: "#3b82f6" },
    "2026-04-17": { name: "Thingyan Festival (Water Festival)", note: "", color: "#3b82f6" },
    "2026-04-18": { name: "Thingyan Festival (Water Festival)", note: "", color: "#3b82f6" },
    "2026-04-19": { name: "Thingyan Festival (Water Festival)", note: "", color: "#3b82f6" },
    "2026-05-01": { name: "Labour Day", note: "", color: "#f97316" },
    "2026-05-10": { name: "Full Moon of Kason", note: "", color: "#8b5cf6" },
    "2026-07-19": { name: "Martyrs' Day", note: "", color: "#ef4444" },
    "2026-07-20": { name: "Full Moon of Waso", note: "", color: "#8b5cf6" },
    "2026-10-05": { name: "Thadingyut (Lighting Festival)", note: "", color: "#eab308" },
    "2026-10-22": { name: "Tazaungdaung Festival", note: "", color: "#ec4899" },
    "2026-11-09": { name: "National Day", note: "", color: "#ef4444" },
    "2026-11-25": { name: "Full Moon of Natdaw", note: "", color: "#8b5cf6" }
};

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
            cell.setAttribute("onclick", "openEditModal('" + dateKey + "')");
        }

        var html = "";
        if (td && hol) {
            html += '<span class="w-6 h-6 rounded-full bg-indigo-500 text-white text-[11px] font-black flex items-center justify-center today-ring">' + d + '</span>';
        } else if (td) {
            html += '<span class="w-6 h-6 rounded-full bg-indigo-500 text-white text-[11px] font-black flex items-center justify-center today-ring">' + d + '</span>';
        } else if (hol) {
            html += '<span class="w-6 h-6 rounded-full text-white text-[11px] font-black flex items-center justify-center" style="background:' + hColor + '">' + d + '</span>';
        } else {
            html += '<span class="text-[11px] font-semibold text-slate-600">' + d + '</span>';
        }

        if (hol) {
            html += '<span class="mt-0.5 px-1 py-0.5 text-[7px] font-black rounded uppercase tracking-wider leading-none text-center max-w-full truncate hidden sm:block" style="background:' + hexToRgba(hColor, 0.15) + ';color:' + hColor + '">' +
                (name.length > 12 ? name.substring(0, 10) + "…" : name) + '</span>';
            html += '<span class="mt-0.5 text-[7px] font-bold sm:hidden" style="color:' + hColor + '">●</span>';
        }

        if (hol) {
            html += '<div class="holiday-tooltip absolute bottom-full left-1/2 -translate-x-1/2 mb-2 z-50">';
            html += '  <div class="bg-slate-900 text-white text-[10px] font-semibold rounded-lg px-3 py-2 shadow-lg whitespace-nowrap">';
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

        html += '<div class="px-4 py-3 flex items-center gap-3 cursor-pointer hover:bg-slate-50 transition ' + (isPast ? 'opacity-50' : '') + '" onclick="openEditModal(\'' + display[k] + '\')">';
        html += '  <div class="w-10 h-10 rounded-xl flex flex-col items-center justify-center shrink-0" style="background:' + hexToRgba(hCol, 0.15) + ';color:' + hCol + '">';
        html += '    <span class="text-sm font-black leading-none">' + dy + '</span>';
        html += '    <span class="text-[7px] font-bold uppercase leading-none mt-0.5">' + monthNames[mo].substring(0, 3) + '</span>';
        html += '  </div>';
        html += '  <div class="flex-1 min-w-0">';
        html += '    <p class="text-xs font-bold text-slate-700 truncate">' + hData.name + '</p>';
        html += '    <p class="text-[10px] text-slate-400">' + dayName + ', ' + monthNames[mo] + ' ' + dy + ', ' + yr + '</p>';
        html += '  </div>';
        html += '  <span class="text-[10px] text-slate-300 shrink-0">›</span>';
        html += '</div>';
    }
    if (!query && upcoming.length > 5) {
        html += '<div class="px-4 py-2.5 bg-slate-50 text-center">';
        html += '  <span class="text-[10px] font-bold text-slate-400">+' + (upcoming.length - 5) + ' more holidays this year</span>';
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
        out += "<span class=\"text-[10px] font-semibold text-slate-600 truncate max-w-[120px]\" title=\"" + tip + "\">" + label + "</span>";
        out += "</div>";
    }
    if (!out) out = "<p class=\"text-[10px] text-slate-400\">No holidays configured.</p>";
    el.innerHTML = out;
}
function prevMonth() { currentMonth--; if (currentMonth < 0) { currentMonth = 11; currentYear--; } render(); }
function nextMonth() { currentMonth++; if (currentMonth > 11) { currentMonth = 0; currentYear++; } render(); }
function goToday() { currentYear = today.getFullYear(); currentMonth = today.getMonth(); render(); }

// ── Init ──
render();

// ── Modal Functions ──
function openAddModal() {
    document.getElementById("holiday-modal").classList.remove("hidden");
    document.getElementById("modal-title").textContent = "Add Public Holiday";
    document.getElementById("modal-edit-key").value = "";
    document.getElementById("modal-holiday-date").value = "";
    document.getElementById("modal-holiday-name").value = "";
    document.getElementById("modal-holiday-note").value = "";
    document.getElementById("modal-error").classList.add("hidden");
    document.getElementById("modal-delete-btn").classList.add("hidden");
    document.getElementById("modal-submit-btn").textContent = "Add Holiday";
    document.getElementById("modal-holiday-date").removeAttribute("readonly");
    pickColor("#f43f5e");
    document.getElementById("modal-holiday-date").focus();
}

function openEditModal(dateKey) {
    var h = holidays[dateKey];
    if (!h) return;
    document.getElementById("holiday-modal").classList.remove("hidden");
    document.getElementById("modal-title").textContent = "Edit Holiday";
    document.getElementById("modal-edit-key").value = dateKey;
    document.getElementById("modal-holiday-date").value = dateKey;
    document.getElementById("modal-holiday-date").setAttribute("readonly", true);
    document.getElementById("modal-holiday-name").value = h.name;
    document.getElementById("modal-holiday-note").value = h.note || "";
    document.getElementById("modal-error").classList.add("hidden");
    document.getElementById("modal-delete-btn").classList.remove("hidden");
    document.getElementById("modal-submit-btn").textContent = "Save Changes";
    pickColor(h.color || "#f43f5e");
    document.getElementById("modal-holiday-name").focus();
}

function closeModal() { document.getElementById("holiday-modal").classList.add("hidden"); }

function pickColor(hex) {
    document.getElementById("modal-holiday-color").value = hex;
    document.getElementById("color-hex").textContent = hex;
    document.querySelectorAll(".color-swatch").forEach(function(el) {
        if (el.getAttribute("data-color") === hex) {
            el.classList.add("ring-2", "ring-offset-1");
        } else {
            el.classList.remove("ring-2", "ring-offset-1");
        }
    });
}

function handleSaveHoliday(e) {
    e.preventDefault();
    var editKey = document.getElementById("modal-edit-key").value;
    var dateVal = document.getElementById("modal-holiday-date").value;
    var nameVal = document.getElementById("modal-holiday-name").value.trim();
    var noteVal = document.getElementById("modal-holiday-note").value.trim();
    var colorVal = document.getElementById("modal-holiday-color").value;
    var errEl = document.getElementById("modal-error");
    errEl.classList.add("hidden");

    if (!dateVal || !nameVal) {
        errEl.textContent = "Date and Holiday Name are required.";
        errEl.classList.remove("hidden");
        return false;
    }

    if (editKey) {
        holidays[editKey] = { name: nameVal, note: noteVal, color: colorVal };
        render(); closeModal();
        showSuccessToast(nameVal + " updated successfully!");
    } else {
        if (holidays.hasOwnProperty(dateVal)) {
            errEl.textContent = "A holiday already exists for this date (" + holidays[dateVal].name + ").";
            errEl.classList.remove("hidden");
            return false;
        }
        holidays[dateVal] = { name: nameVal, note: noteVal, color: colorVal };
        render(); closeModal();
        showSuccessToast(nameVal + " added successfully!");
    }
    return false;
}

function handleDeleteHoliday() {
    var editKey = document.getElementById("modal-edit-key").value;
    if (!editKey || !holidays[editKey]) return;
    var name = holidays[editKey].name;
    if (confirm('Delete "' + name + '" on ' + editKey + '?')) {
        delete holidays[editKey]; render(); closeModal();
        showSuccessToast(name + " deleted.");
    }
}

function showSuccessToast(msg) {
    var toast = document.createElement("div");
    toast.className = "fixed bottom-6 right-6 z-[1000] bg-emerald-600 text-white text-xs font-bold px-4 py-3 rounded-xl shadow-lg flex items-center gap-2 transition-all duration-300";
    toast.style.opacity = "0"; toast.style.transform = "translateY(10px)";
    toast.innerHTML = '<span class="text-base">✓</span> ' + msg;
    document.body.appendChild(toast);
    requestAnimationFrame(function() { toast.style.opacity = "1"; toast.style.transform = "translateY(0)"; });
    setTimeout(function() {
        toast.style.opacity = "0"; toast.style.transform = "translateY(10px)";
        setTimeout(function() { toast.remove(); }, 300);
    }, 2500);
}
</script>

</body>
</html>
