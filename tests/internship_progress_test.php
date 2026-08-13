<?php
/**
 * tests/internship_progress_test.php
 *
 * Regression tests for the shared internship progress/week calculations
 * (config/internship_progress.php) used by:
 *   - supervisor/supervisor-dashboard.php
 *   - supervisor/my-students.php
 *   - supervisor/supervisor-review.php
 *   - supervisor/view-student-dashboard.php
 *   - instructor/instructor-dashboard.php
 *
 * Contract (config/week_helper.php Sun→Sat week convention):
 *   - Week 1 starts on internship_start_date and ends on the next Saturday.
 *   - Every later week is a 7-day block. Total weeks = number of distinct
 *     weeks between start and end date (inclusive), at least 1.
 *   - internship_current_week() clamps to the internship's total weeks so an
 *     ended internship never reports a week past its own duration.
 *   - internship_completed_weeks() counts DISTINCT week_number from
 *     report_evaluations where report_status <> 'rejected'.
 *   - internship_attendance() counts present vs absent/leave records in
 *     daily_logs (optional date range scopes it to one week); expected =
 *     present + absent, rate = present / expected * 100.
 *
 * The pure functions are asserted offline. A read-only sanity check against
 * the live database verifies the integration path and is skipped gracefully
 * when the DB is unreachable. The live database is never modified.
 *
 * Run with the PHP CLI from the project root:
 *   php tests/internship_progress_test.php
 */

$ROOT = __DIR__ . '/..';

// ── Minimal assertion harness ──────────────────────────────────
$GLOBALS['assertions'] = 0;
$GLOBALS['failures']   = 0;

function check(bool $cond, string $label): void
{
    $GLOBALS['assertions']++;
    if ($cond) {
        echo "  [PASS] {$label}\n";
    } else {
        $GLOBALS['failures']++;
        echo "  [FAIL] {$label}\n";
    }
}

// ── Load the helper under test ─────────────────────────────────
require $ROOT . '/config/internship_progress.php';

echo "== Scenario 1: internship_total_weeks\n";

// Week 1 ends on the next Saturday on/after the start date.
// Start Mon 2026-05-04 (N=1): week 1 = Mon..Sat, week 2 starts Sun 2026-05-10.
check(internship_total_weeks('2026-05-04', '2026-05-09') === 1,
    'start Mon, end Saturday of week 1 -> 1 week');
check(internship_total_weeks('2026-05-04', '2026-05-10') === 2,
    'start Mon, end Sunday (week 2 start) -> 2 weeks');
check(internship_total_weeks('2026-05-04', '2026-05-11') === 2,
    'start Mon, end Monday of week 2 -> 2 weeks');

// Start Tue 2026-05-05 (N=2): week 1 = Tue..Sat, week 2 starts Sun 2026-05-10.
check(internship_total_weeks('2026-05-05', '2026-05-09') === 1,
    'start Tue, end Saturday of week 1 -> 1 week');
check(internship_total_weeks('2026-05-05', '2026-07-31') === 13,
    'start Tue 2026-05-05 .. end 2026-07-31 -> 13 weeks (12-week internship + partial)');

// Same-day internship is exactly one week.
check(internship_total_weeks('2026-05-05', '2026-05-05') === 1,
    'start == end (same day) -> 1 week');

// Inverted dates degrade to the minimum sensible value.
check(internship_total_weeks('2026-07-31', '2026-05-05') === 1,
    'end before start -> 1 week');

// Missing dates.
check(internship_total_weeks(null, '2026-07-31') === 0,
    'no start date -> 0 (duration unknown)');
check(internship_total_weeks('2026-05-05', null) === 12,
    'start only, no end date -> default duration of 12 weeks');

echo "\n== Scenario 2: internship_current_week\n";

$today = new DateTime('2026-05-05'); // start date (Tuesday)
check(internship_current_week('2026-05-05', '2026-07-31', $today) === 1,
    'on start date -> week 1');

$today = new DateTime('2026-05-09'); // Saturday, end of week 1
check(internship_current_week('2026-05-05', '2026-07-31', $today) === 1,
    'Saturday of week 1 -> week 1');

$today = new DateTime('2026-05-10'); // Sunday, start of week 2
check(internship_current_week('2026-05-05', '2026-07-31', $today) === 2,
    'Sunday -> week 2');

$today = new DateTime('2026-05-12'); // Tuesday, week 2
check(internship_current_week('2026-05-05', '2026-07-31', $today) === 2,
    'start date + 7 days -> week 2');

$today = new DateTime('2026-08-13'); // after internship end
check(internship_current_week('2026-05-05', '2026-07-31', $today) === 13,
    'after internship ended -> clamped to total weeks (13), not 12 or 14+');

$today = new DateTime('2026-05-04'); // before start date
check(internship_current_week('2026-05-05', '2026-07-31', $today) === 1,
    'before start date -> week 1 (not started)');

check(internship_current_week(null, '2026-07-31') === 1,
    'no start date -> week 1 fallback');

check(internship_current_week('2026-05-05', '2026-05-09', new DateTime('2026-05-05')) === 1,
    '1-week internship, on the only week -> week 1');

check(internship_current_week('2026-05-05', '2026-05-09', new DateTime('2026-05-20')) === 1,
    '1-week internship, after end -> clamped to week 1');

echo "\n== Scenario 2b: empty-string dates (real data stores '' not NULL)\n";

check(internship_total_weeks('', '2026-07-31') === 0,
    "empty start string -> 0 (duration unknown)");
check(internship_total_weeks('2026-05-05', '') === 12,
    "start set, empty end string -> default duration of 12 weeks");
check(internship_current_week('', '2026-07-31') === 1,
    "empty start string -> week 1 fallback");

echo "\n== Scenario 3: live database sanity (read-only, graceful skip)\n";

try {
    $host = getenv('DB_HOST') ?: 'localhost';
    $port = getenv('DB_PORT') ?: '3306';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS') ?: 'root';
    $pdo  = new PDO("mysql:host={$host};port={$port};dbname=intern_report_db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $prog = internship_progress($pdo, 14, '2026-05-05', '2026-07-31');
    check($prog['total'] === 13, 'live progress: total = 13 for the known 13-week student');
    check($prog['completed'] >= 0 && $prog['completed'] <= $prog['total'],
        'live progress: completed is within [0, total]');
    check($prog['pct'] === min(100, (int) round(($prog['completed'] / $prog['total']) * 100)),
        'live progress: pct matches the documented formula');

    $progEmpty = internship_progress($pdo, 14, '', '2026-07-31');
    check($progEmpty['total'] === 0 && $progEmpty['pct'] === 0,
        'live progress: empty start string yields total 0 and pct 0 (never divide by zero)');

    $tot = internship_total_weeks('2026-05-05', '2026-07-31');
    check($tot === 13, 'live helper: 13-week internship derived from dates');

    // ── internship_attendance() ────────────────────────────────────
    // Shared by supervisor/view-student-dashboard.php and
    // supervisor/supervisor-review.php. Read-only; never modifies data.
    $att = internship_attendance($pdo, 14);
    check($att['expected'] === $att['present'] + $att['absent'],
        'live attendance: expected = present + absent');
    check($att['rate'] === ($att['expected'] > 0 ? (int) round(($att['present'] / $att['expected']) * 100) : 0),
        'live attendance: rate matches the documented formula');
    check($att['present'] >= 0 && $att['absent'] >= 0,
        'live attendance: counts are non-negative');

    $attWeek1 = internship_attendance($pdo, 14, '2026-05-05', '2026-05-11');
    check($attWeek1['expected'] === 4,
        'live attendance: Week 1 (05-05..05-11) has 4 logged days');
    check($attWeek1['present'] === 3 && $attWeek1['absent'] === 1 && $attWeek1['rate'] === 75,
        'live attendance: Week 1 = 3 present / 1 absent / 75%');

    $attWeek2 = internship_attendance($pdo, 14, '2026-05-12', '2026-05-18');
    check($attWeek2['expected'] === 0 && $attWeek2['rate'] === 0,
        'live attendance: Week 2 (no logs) = 0 expected / 0% (never divide by zero)');

    $attEmpty = internship_attendance($pdo, 999999);
    check($attEmpty['expected'] === 0 && $attEmpty['rate'] === 0,
        'live attendance: unknown internship = 0 expected / 0%');

    echo "  (live database checked)\n";
} catch (Exception $e) {
    echo "  [SKIP] live database unreachable: {$e->getMessage()}\n";
}

echo "\n-----------------------------------------------\n";
if ($GLOBALS['failures'] === 0) {
    echo "ALL {$GLOBALS['assertions']} ASSERTIONS PASSED\n";
    exit(0);
} else {
    echo "{$GLOBALS['failures']} of {$GLOBALS['assertions']} assertions FAILED\n";
    exit(1);
}
