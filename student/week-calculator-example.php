<?php
/**
 * Standalone Example — Student Portal Date Validation & Custom Week Calculator
 *
 * Demonstrates:
 *   1. PHP backend validation (date range + weekend check)
 *   2. PHP getInternshipWeekNumber() — custom Sun→Sat week logic
 *   3. HTML/Tailwind form with date input
 *   4. JavaScript frontend validation (disable weekends + enforce date range)
 */

require_once __DIR__ . '/../config/week_helper.php';

// ─── Sample config (replace with session / DB values) ─────────────
$intern_start = '2026-05-05'; // Tuesday
$intern_end   = '2026-06-30'; // Tuesday

$week_number  = null;
$selected     = '';
$errors       = [];
$submitted    = false;

// ─── Handle form submission ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_date'])) {
    $submitted = true;
    $selected  = trim($_POST['log_date']);

    // Backend validation
    $error = validateLogDate($selected, $intern_start, $intern_end);
    if ($error === 'date_out_of_range') {
        $errors[] = "Date must be between {$intern_start} and {$intern_end}.";
    } elseif ($error === 'date_weekend') {
        $errors[] = 'Weekends (Saturday & Sunday) are not allowed.';
    }

    if (empty($errors)) {
        $week_number = getInternshipWeekNumber($intern_start, $selected);
    }
}

// ─── Build all week ranges for display ────────────────────────────
$all_weeks = [];
$w = 1;
while (true) {
    $range = getWeekRange($intern_start, $w);
    if (!$range || $range['start'] > $intern_end) {
        break;
    }
    $all_weeks[$w] = $range;
    $w++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Week Calculator Example — InternReport</title>
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
        .active-week { background-color: #eef2ff; color: #4f46e5; }
    </style>
</head>
<body class="bg-slate-50 font-sans antialiased min-h-screen p-8">

<div class="max-w-2xl mx-auto space-y-6">

    <!-- Header -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
        <h1 class="text-lg font-black text-slate-800 mb-1">Custom Week Calculator</h1>
        <p class="text-xs text-slate-400 leading-relaxed">
            Internship: <strong class="text-slate-600"><?= $intern_start ?></strong> →
            <strong class="text-slate-600"><?= $intern_end ?></strong>
            (Weeks run <strong class="text-slate-600">Sunday → Saturday</strong>)
        </p>
    </div>

    <!-- Date Input Form -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
        <h2 class="text-sm font-black text-slate-800 uppercase tracking-wider mb-4 flex items-center gap-2">
            <span class="p-1 bg-blue-50 text-blue-600 rounded">📅</span> Select a Log Date
        </h2>

        <form method="POST" id="logForm" class="space-y-4">
            <div>
                <label class="block text-sm font-bold text-slate-500 mb-1">Date</label>
                <input
                    type="date"
                    name="log_date"
                    id="log_date"
                    value="<?= htmlspecialchars($selected) ?>"
                    min="<?= htmlspecialchars($intern_start) ?>"
                    max="<?= htmlspecialchars($intern_end) ?>"
                    required
                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800
                           focus:outline-none focus:border-blue-500 focus:bg-white transition"
                >
                <p class="text-sm text-slate-400 mt-1">
                    Allowed: <?= (new DateTime($intern_start))->format('d M Y') ?> –
                    <?= (new DateTime($intern_end))->format('d M Y') ?> (Mon–Fri only)
                </p>
            </div>

            <div class="flex justify-end">
                <button type="submit" name="calculate"
                    class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs
                           rounded-xl shadow-sm transition cursor-pointer">
                    Calculate Week Number
                </button>
            </div>
        </form>
    </div>

    <!-- Result -->
    <?php if ($submitted && empty($errors) && $week_number !== null): ?>
    <div class="bg-gradient-to-r from-indigo-50 to-purple-50 border border-indigo-200 rounded-2xl p-6">
        <div class="flex items-center gap-4">
            <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-indigo-500 to-purple-600
                        text-white flex items-center justify-center text-2xl font-black shadow-lg shadow-indigo-500/20">
                W<?= $week_number ?>
            </div>
            <div>
                <h3 class="text-sm font-bold text-slate-700">
                    Week <?= $week_number ?>
                </h3>
                <p class="text-sm text-slate-500 mt-0.5">
                    <strong><?= htmlspecialchars($selected) ?></strong> falls in Week <?= $week_number ?>
                </p>
                <?php if (isset($all_weeks[$week_number])): ?>
                <p class="text-sm text-slate-400 mt-0.5">
                    (<?= $all_weeks[$week_number]['start'] ?> → <?= $all_weeks[$week_number]['end'] ?>)
                </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Errors -->
    <?php if (!empty($errors)): ?>
    <div class="bg-red-50 border border-red-200 rounded-2xl p-5">
        <div class="flex items-start gap-3">
            <div class="w-8 h-8 rounded-full bg-red-100 text-red-500 flex items-center justify-center text-sm shrink-0">
                ✕
            </div>
            <div>
                <h3 class="text-xs font-bold text-red-700 mb-1">Validation Error</h3>
                <?php foreach ($errors as $err): ?>
                <p class="text-xs text-red-600">• <?= htmlspecialchars($err) ?></p>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Week Reference Table -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white">
            <h3 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                <span class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-500 flex items-center justify-center text-sm">📊</span>
                Week Reference (<?= count($all_weeks) ?> weeks)
            </h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50/80 text-slate-500 font-semibold uppercase tracking-wider text-sm">
                        <th class="px-5 py-3 text-left">Week</th>
                        <th class="px-5 py-3 text-left">Start (Sunday)</th>
                        <th class="px-5 py-3 text-left">End (Saturday)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($all_weeks as $wn => $wr): ?>
                    <tr class="hover:bg-slate-50/50 transition-colors duration-150
                               <?= ($week_number == $wn) ? 'active-week' : '' ?>">
                        <td class="px-5 py-3 font-bold text-slate-700">
                            Week <?= $wn ?>
                            <?php if ($wn === 1): ?>
                            <span class="text-sm text-amber-600 bg-amber-50 px-1.5 py-0.5 rounded ml-1">partial</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-3 text-xs font-mono text-slate-600">
                            <?= (new DateTime($wr['start']))->format('D, d M Y') ?>
                        </td>
                        <td class="px-5 py-3 text-xs font-mono text-slate-600">
                            <?= (new DateTime($wr['end']))->format('D, d M Y') ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- PHP Source Reference -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
        <h3 class="text-sm font-black text-slate-800 uppercase tracking-wider mb-3 flex items-center gap-2">
            <span class="p-1 bg-amber-50 text-amber-600 rounded">⚙️</span> PHP Function Usage
        </h3>
        <pre class="bg-slate-900 text-emerald-400 text-sm font-mono p-4 rounded-xl overflow-x-auto"><code>// Include the helper
require_once 'config/week_helper.php';

// Get week number
$week = getInternshipWeekNumber('2026-05-05', '2026-05-09'); // → 1
$week = getInternshipWeekNumber('2026-05-05', '2026-05-12'); // → 2

// Validate a date
$error = validateLogDate('2026-05-09', '2026-05-05', '2026-06-30'); // → null (valid)
$error = validateLogDate('2026-05-10', '2026-05-05', '2026-06-30'); // → 'date_weekend'

// Get week date range
$range = getWeekRange('2026-05-05', 1);
// → ['start' => '2026-05-05', 'end' => '2026-05-09']</code></pre>
    </div>

</div>

<!-- ═══════════════════════════════════════════════════════════════════
     JAVASCRIPT VALIDATION
     - Disables Saturday & Sunday in the date picker
     - Restricts min/max to internship range
     - Validates on submit
     ═══════════════════════════════════════════════════════════════════ -->
<script>
(function () {
    var internStart = '<?= $intern_start ?>';
    var internEnd   = '<?= $intern_end ?>';
    var dateInput   = document.getElementById('log_date');

    // ── Disable weekends in the native date picker ──────────────────
    // Browsers don't support disabling specific days natively, so we
    // add a constraint validation pattern and validate on change.
    if (dateInput) {
        dateInput.addEventListener('change', function () {
            var val = this.value;
            if (!val) return;

            var day = new Date(val + 'T00:00:00').getDay(); // 0=Sun, 6=Sat

            if (day === 0 || day === 6) {
                this.setCustomValidity('Weekends are not allowed. Please select Monday–Friday.');
                this.reportValidity();
                // Auto-advance to next Monday
                var next = new Date(val + 'T00:00:00');
                var offset = day === 6 ? 2 : 1; // Sat→Mon, Sun→Mon
                next.setDate(next.getDate() + offset);
                var y = next.getFullYear();
                var m = String(next.getMonth() + 1).padStart(2, '0');
                var d = String(next.getDate()).padStart(2, '0');
                this.value = y + '-' + m + '-' + d;
                this.setCustomValidity('');
            } else {
                this.setCustomValidity('');
            }
        });

        // Prevent manual entry of weekends
        dateInput.addEventListener('input', function () {
            this.setCustomValidity('');
        });
    }

    // ── Form submit validation ──────────────────────────────────────
    var form = document.getElementById('logForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            var val = dateInput.value;
            if (!val) return;

            // Range check
            if (internStart && val < internStart) {
                alert('Date cannot be before your internship start date (' + internStart + ').');
                e.preventDefault();
                return false;
            }
            if (internEnd && val > internEnd) {
                alert('Date cannot be after your internship end date (' + internEnd + ').');
                e.preventDefault();
                return false;
            }

            // Weekend check
            var day = new Date(val + 'T00:00:00').getDay();
            if (day === 0 || day === 6) {
                alert('Weekends (Saturday & Sunday) are not allowed. Please select a weekday (Monday–Friday).');
                e.preventDefault();
                return false;
            }

            return true;
        });
    }
})();
</script>

</body>
</html>
