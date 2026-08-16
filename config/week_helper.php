<?php
/**
 * ══════════════════════════════════════════════════════════════════════
 *  WEEK HELPER — Internship Week Calculator Helpers
 * ══════════════════════════════════════════════════════════════════════
 */

/**
 * Get the week number for a given date relative to the internship start.
 *
 * @param string $internship_start_date  Y-m-d format
 * @param string $selected_date          Y-m-d format
 * @return int  Week number (1-based). Returns 0 if date is before start.
 */
function getInternshipWeekNumber(?string $internship_start_date, string $selected_date): int
{
    if (empty($internship_start_date)) {
        return 0;
    }
    $start = new DateTime($internship_start_date);
    $selected = new DateTime($selected_date);

    // Selected date is before internship start — invalid
    if ($selected < $start) {
        return 0;
    }

    // Find the end of Week 1: the next Saturday on or after the start date.
    // DateTime::N: 1=Monday … 6=Saturday, 7=Sunday
    $dayOfWeek = (int) $start->format('N'); // 1-7

    if ($dayOfWeek === 6) {
        // Start IS Saturday → Week 1 ends today
        $endOfWeek1 = clone $start;
    } else {
        // Days until next Saturday: (6 - dayOfWeek + 7) % 7, but 0 means today is Sat
        // Since Saturday is N=6 and we already handled that, we can use:
        $daysToSat = (6 - $dayOfWeek + 7) % 7;
        $endOfWeek1 = (clone $start)->modify("+{$daysToSat} days");
    }

    // If the selected date falls within Week 1
    if ($selected <= $endOfWeek1) {
        return 1;
    }

    // For dates after Week 1, compute how many days past the end of Week 1
    $diff = (int) $selected->diff($endOfWeek1)->days;
    // The selected date is after endOfWeek1, so diff >= 1
    $week = 1 + (int) ceil($diff / 7);

    return $week;
}

/**
 * Validate a log date: must be within internship range.
 *
 * @param string $log_date               Y-m-d
 * @param string|null $internship_start  Y-m-d or null
 * @param string|null $internship_end    Y-m-d or null
 * @return string|null  Error message key, or null if valid.
 */
function validateLogDate(string $log_date, ?string $internship_start, ?string $internship_end): ?string
{
    if ($internship_start && $log_date < $internship_start) {
        return 'date_out_of_range';
    }
    if ($internship_end && $log_date > $internship_end) {
        return 'date_out_of_range';
    }

    return null; // valid
}

/**
 * Get the date range (start → end) for a specific week number.
 *
 * @param string $internship_start_date  Y-m-d
 * @param int $week_number               1-based
 * @return array{start: string, end: string}|null  Y-m-d strings, or null if invalid
 */
function getWeekRange(string $internship_start_date, int $week_number): ?array
{
    if ($week_number < 1) {
        return null;
    }

    $start = new DateTime($internship_start_date);

    if ($week_number === 1) {
        $dayOfWeek = (int) $start->format('N');
        $daysToSat = $dayOfWeek === 6 ? 0 : (6 - $dayOfWeek + 7) % 7;
        $end = (clone $start)->modify("+{$daysToSat} days");
        return [
            'start' => $start->format('Y-m-d'),
            'end'   => $end->format('Y-m-d'),
        ];
    }

    // For week 2+, we need to find the Sunday that starts this week.
    // Week 1 ends on the Saturday after (or on) the start date.
    $dayOfWeek = (int) $start->format('N');
    $daysToSat = $dayOfWeek === 6 ? 0 : (6 - $dayOfWeek + 7) % 7;
    $endOfWeek1 = (clone $start)->modify("+{$daysToSat} days");

    // Sunday after end of week 1 = start of week 2
    $weekStart = (clone $endOfWeek1)->modify('+1 day');
    // Advance by ($week_number - 2) full weeks
    if ($week_number > 2) {
        $weekStart->modify('+' . (($week_number - 2) * 7) . ' days');
    }
    $weekEnd = (clone $weekStart)->modify('+6 days');

    return [
        'start' => $weekStart->format('Y-m-d'),
        'end'   => $weekEnd->format('Y-m-d'),
    ];
}

/**
 * Build a list of internship weeks starting from the given date.
 *
 * Produces an associative array keyed by 1-based week number. Each item contains:
 *  - 'start': Y-m-d start date (Sunday for weeks 2+; week 1 may start mid-week)
 *  - 'end':   Y-m-d end date (Saturday)
 *  - 'label': human-friendly range like "05 May 2026 — 11 May 2026"
 *
 * This function is defensive: it validates input dates and will stop early
 * if an optional internship end date is provided and the computed week
 * starts after that end date.
 *
 * @param string $internship_start_date Y-m-d
 * @param int $num_weeks Number of weeks to generate (default 12)
 * @param string|null $internship_end_date Optional Y-m-d to cap weeks
 * @return array<int,array{start:string,end:string,label:string}>
 */
function buildInternshipWeeks(string $internship_start_date, int $num_weeks = 12, ?string $internship_end_date = null): array
{
    if ($num_weeks < 1) {
        return [];
    }

    try {
        $start = new DateTime($internship_start_date);
    } catch (Exception $e) {
        return [];
    }

    $endLimit = null;
    if (!empty($internship_end_date)) {
        try {
            $endLimit = new DateTime($internship_end_date);
        } catch (Exception $e) {
            $endLimit = null;
        }
    }

    $weeks = [];

    // Week 1: starts at the internship start date, ends on the next Saturday (N=6)
    $dayOfWeek = (int) $start->format('N'); // 1=Mon ... 6=Sat, 7=Sun
    $daysToSat = $dayOfWeek === 6 ? 0 : (6 - $dayOfWeek + 7) % 7;
    $endOfWeek1 = (clone $start)->modify("+{$daysToSat} days");

    $weeks[1] = [
        'start' => $start->format('Y-m-d'),
        'end'   => $endOfWeek1->format('Y-m-d'),
        'label' => $start->format('d M Y') . ' — ' . $endOfWeek1->format('d M Y'),
    ];

    if ($num_weeks === 1) {
        return $weeks;
    }

    // Week 2 starts on the Sunday after endOfWeek1
    $currentStart = (clone $endOfWeek1)->modify('+1 day'); // Sunday

    for ($i = 2; $i <= $num_weeks; $i++) {
        // If an end limit is set and the week start is after it, stop building
        if ($endLimit && $currentStart > $endLimit) {
            break;
        }

        $currentEnd = (clone $currentStart)->modify('+6 days'); // Saturday

        // If end limit exists and the week end is after end limit, cap it to endLimit
        $weekEnd = $currentEnd;
        if ($endLimit && $currentEnd > $endLimit) {
            $weekEnd = clone $endLimit;
        }

        $weeks[$i] = [
            'start' => $currentStart->format('Y-m-d'),
            'end'   => $weekEnd->format('Y-m-d'),
            'label' => $currentStart->format('d M Y') . ' — ' . $weekEnd->format('d M Y'),
        ];

        // Advance to next week's Sunday
        $currentStart->modify('+7 days');

        // If we capped the end due to endLimit, there's no point building further
        if ($endLimit && $weekEnd >= $endLimit) {
            break;
        }
    }

    return $weeks;
}

// Only output HTML when accessed directly (not when included by other files)
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'week_helper.php'):
    require_once __DIR__ . '/db.php';
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $db = $mysqli ?? $conn ?? null;
    $student_user_id = (int)($_SESSION['user_id'] ?? 0);

    $internship_start = null;
    $internship_end   = null;
    if ($student_user_id > 0 && $db) {
        $sp_stmt = $db->prepare("SELECT internship_start_date, internship_end_date FROM student_profiles WHERE user_id = ?");
        $sp_stmt->bind_param("i", $student_user_id);
        $sp_stmt->execute();
        $sp_res = $sp_stmt->get_result();
        $sp_row = $sp_res ? $sp_res->fetch_assoc() : null;
        if ($sp_row) {
            $internship_start = $sp_row['internship_start_date'] ?: null;
            $internship_end   = $sp_row['internship_end_date'] ?: null;
        }
    }

    $current_week = 0;
    $weeks = [];
    if ($internship_start) {
        $today_str    = (new DateTime())->format('Y-m-d');
        $current_week = getInternshipWeekNumber($internship_start, $today_str);
        $weeks        = buildInternshipWeeks($internship_start, 12, $internship_end);
    }

    $action_error  = null;
    $action_result = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $action = $_POST['action'];
        if ($action === 'week_number' && !empty($_POST['test_date'])) {
            $test_date = $_POST['test_date'];
            if (!$internship_start) {
                $action_error = 'No internship start date defined.';
            } else {
                $wn = getInternshipWeekNumber($internship_start, $test_date);
                if ($wn === 0) {
                    $action_error = 'The selected date is before the internship start date.';
                } else {
                    $action_result = ['type' => 'week_number', 'date' => $test_date, 'week' => $wn];
                }
            }
        } elseif ($action === 'validate_date' && !empty($_POST['test_date'])) {
            $test_date = $_POST['test_date'];
            $err = validateLogDate($test_date, $internship_start, $internship_end);
            if ($err) {
                $action_error = 'Date is outside the allowed internship date range.';
            } else {
                $action_result = ['type' => 'validate_date', 'date' => $test_date];
            }
        } elseif ($action === 'week_range' && isset($_POST['test_week'])) {
            $test_week = (int)$_POST['test_week'];
            if (!$internship_start) {
                $action_error = 'No internship start date defined.';
            } else {
                $range = getWeekRange($internship_start, $test_week);
                if (!$range) {
                    $action_error = 'Invalid week number.';
                } else {
                    $action_result = ['type' => 'week_range', 'week' => $test_week, 'start' => $range['start'], 'end' => $range['end']];
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
    <title>Week Helper — Internship Week Calculator</title>
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
</head>
<body class="bg-slate-50 font-sans antialiased min-h-screen p-6">

<div class="max-w-3xl mx-auto space-y-6">

    <!-- Header -->
    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-6">
        <h1 class="text-lg font-black text-slate-800 mb-1">Week Helper — Internship Calculator</h1>
        <p class="text-xs text-slate-400">Week rules: <code class="bg-slate-100 px-1 rounded">Sun → Sat</code>. Week 1 ends on the next Saturday after the internship start date.</p>
        <?php if ($internship_start): ?>
        <div class="mt-3 flex flex-wrap gap-3 text-sm">
            <span class="px-2.5 py-1 rounded-lg bg-blue-50 text-blue-700 font-bold">Start: <?= htmlspecialchars($internship_start) ?></span>
            <span class="px-2.5 py-1 rounded-lg bg-purple-50 text-purple-700 font-bold">End: <?= htmlspecialchars($internship_end ?: 'N/A') ?></span>
            <span class="px-2.5 py-1 rounded-lg bg-emerald-50 text-emerald-700 font-bold">Today → Week <?= $current_week ?: '—' ?></span>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!$internship_start): ?> 
        <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 flex items-start gap-3">
        <div class="w-8 h-8 rounded-full bg-amber-100 text-amber-500 flex items-center justify-center text-sm shrink-0">!</div>
        <div>
            <h3 class="text-xs font-bold text-amber-700">No Internship Dates Found</h3>
            <p class="text-xs text-amber-600 mt-0.5">Your student profile does not have an internship start date set. Please update your profile first.</p>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($action_error): ?>
    <div class="bg-red-50 border border-red-200 rounded-2xl p-4 flex items-start gap-3">
        <div class="w-8 h-8 rounded-full bg-red-100 text-red-500 flex items-center justify-center text-sm shrink-0">✕</div>
        <div>
            <h3 class="text-xs font-bold text-red-700">Error</h3>
            <p class="text-xs text-red-600 mt-0.5"><?= htmlspecialchars($action_error) ?></p>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($action_result && $action_result['type'] === 'week_number'): ?>
    <div class="bg-blue-50 border border-blue-200 rounded-2xl p-4 flex items-start gap-3">
        <div class="w-8 h-8 rounded-full bg-blue-100 text-blue-500 flex items-center justify-center text-sm shrink-0">i</div>
        <div>
            <h3 class="text-xs font-bold text-blue-700">Week Number Result</h3>
            <p class="text-xs text-blue-600 mt-0.5">
                <span class="font-mono font-bold"><?= htmlspecialchars($action_result['date']) ?></span>
                falls in <span class="font-bold text-blue-800">Week <?= $action_result['week'] ?></span>
            </p>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($action_result && $action_result['type'] === 'validate_date'): ?>
    <div class="bg-emerald-50 border border-emerald-200 rounded-2xl p-4 flex items-start gap-3">
        <div class="w-8 h-8 rounded-full bg-emerald-100 text-emerald-500 flex items-center justify-center text-sm shrink-0">✓</div>
        <div>
            <h3 class="text-xs font-bold text-emerald-700">Date Valid</h3>
            <p class="text-xs text-emerald-600 mt-0.5">
                <span class="font-mono font-bold"><?= htmlspecialchars($action_result['date']) ?></span>
                is within the internship range.
            </p>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($action_result && $action_result['type'] === 'week_range'): ?>
    <div class="bg-violet-50 border border-violet-200 rounded-2xl p-4 flex items-start gap-3">
        <div class="w-8 h-8 rounded-full bg-violet-100 text-violet-500 flex items-center justify-center text-sm shrink-0">#</div>
        <div>
            <h3 class="text-xs font-bold text-violet-700">Week <?= $action_result['week'] ?> Range</h3>
            <p class="text-xs text-violet-600 mt-0.5">
                <span class="font-mono font-bold"><?= htmlspecialchars($action_result['start']) ?></span>
                →
                <span class="font-mono font-bold"><?= htmlspecialchars($action_result['end']) ?></span>
            </p>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($internship_start): ?>

    <!-- Test: Week Number -->
    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-6">
        <h2 class="text-sm font-black text-slate-800 uppercase tracking-wider border-b border-slate-100 pb-3 mb-5">Get Week Number</h2>
        <form method="POST" class="flex items-end gap-3">
            <input type="hidden" name="action" value="week_number">
            <div class="flex-1">
                <label class="block text-sm font-bold text-slate-500 mb-1">Select a date</label>
                <input type="date" name="test_date" required
                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 focus:bg-white transition">
            </div>
            <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer shrink-0">Calculate</button>
        </form>
    </div>

    <!-- Test: Validate Date -->
    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-6">
        <h2 class="text-sm font-black text-slate-800 uppercase tracking-wider border-b border-slate-100 pb-3 mb-5">Validate Log Date</h2>
        <form method="POST" class="flex items-end gap-3">
            <input type="hidden" name="action" value="validate_date">
            <div class="flex-1">
                <label class="block text-sm font-bold text-slate-500 mb-1">Select a date</label>
                <input type="date" name="test_date" required
                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-blue-500 focus:bg-white transition">
            </div>
            <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer shrink-0">Validate</button>
        </form>
    </div>

    <!-- Test: Week Range -->
    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-6">
        <h2 class="text-sm font-black text-slate-800 uppercase tracking-wider border-b border-slate-100 pb-3 mb-5">Get Week Range</h2>
        <form method="POST" class="flex items-end gap-3">
            <input type="hidden" name="action" value="week_range">
            <div class="flex-1">
                <label class="block text-sm font-bold text-slate-500 mb-1">Week number</label>
                <input type="number" name="test_week" min="1" max="52" value="1" required
                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-mono text-slate-800 focus:outline-none focus:border-blue-500 focus:bg-white transition">
            </div>
            <button type="submit" class="px-4 py-2 bg-violet-600 hover:bg-violet-700 text-white font-bold text-xs rounded-xl shadow-sm transition cursor-pointer shrink-0">Get Range</button>
        </form>
    </div>

    <!-- All Weeks Table -->
    <div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm p-6">
        <h2 class="text-sm font-black text-slate-800 uppercase tracking-wider border-b border-slate-100 pb-3 mb-5">All Internship Weeks</h2>
        <?php if (empty($weeks)): ?>
            <p class="text-xs text-slate-400">No weeks generated.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead>
                    <tr class="border-b border-slate-100">
                        <th class="text-left py-2 pr-4 text-sm font-bold text-slate-500 uppercase">Week</th>
                        <th class="text-left py-2 pr-4 text-sm font-bold text-slate-500 uppercase">Start</th>
                        <th class="text-left py-2 pr-4 text-sm font-bold text-slate-500 uppercase">End</th>
                        <th class="text-left py-2 text-sm font-bold text-slate-500 uppercase">Range</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($weeks as $wkNum => $wk): ?>
                    <tr class="border-b border-slate-50 <?= $wkNum == $current_week ? 'bg-blue-50/60' : '' ?>">
                        <td class="py-2 pr-4">
                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-lg <?= $wkNum == $current_week ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600' ?> font-bold text-sm">
                                <?= $wkNum ?>
                            </span>
                        </td>
                        <td class="py-2 pr-4 font-mono text-slate-700"><?= htmlspecialchars($wk['start']) ?></td>
                        <td class="py-2 pr-4 font-mono text-slate-700"><?= htmlspecialchars($wk['end']) ?></td>
                        <td class="py-2 text-slate-500"><?= htmlspecialchars($wk['label']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <?php endif; ?>

</div>
</body>
</html>
<?php endif; ?>
