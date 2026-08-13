<?php
/**
 * tests/dashboard_cards_test.php
 *
 * Regression tests for the Supervisor Dashboard summary cards
 * (supervisor/supervisor-dashboard.php):
 *   - My Students, Total Reports, Companies, Pending Reviews
 *
 * Every count must be:
 *   1. scoped to the logged-in supervisor (sp.supervisor_id = ?)
 *   2. filtered to the dashboard's selected academic year ($ay_sql)
 *   3. computed with the same semantics the target pages use
 *   - Pending Reviews = report_evaluations rows with
 *     report_status = 'approved_by_instructor' AND no matching
 *     supervisor_weekly_evaluations row (supervisor grade pending)
 *
 * And every card must be a clickable <a> link to the existing route:
 *   - My Students      -> my-students.php
 *   - Total Reports    -> supervisor-reports.php
 *   - Companies        -> supervisor-companies.php
 *   - Pending Reviews  -> supervisor-reports.php?status=approved_by_instructor
 *
 * No live database is touched; the deployed handler source is inspected.
 * Run with the PHP CLI from the project root:
 *   php tests/dashboard_cards_test.php
 */

$ROOT = __DIR__ . '/..';
$DASH = $ROOT . '/supervisor/supervisor-dashboard.php';

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

function src(string $path): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException("Cannot read source file: {$path}");
    }
    return $contents;
}

// Strip leading whitespace per line so single-line checks are robust to indentation.
function norm(string $s): string
{
    $s = preg_replace('/\r\n/', "\n", $s);
    return preg_replace('/^[ \t]+/m', '', $s);
}

// Collapse every run of whitespace to a single space so multi-line string
// literals (e.g. the $tr_sql build) can be checked as one line.
function squish(string $s): string
{
    return preg_replace('/\s+/', ' ', norm($s));
}

$content  = norm(src($DASH));
$squished = squish($content);

// The statistics cards block only (between the grid header and the
// "MY STUDENTS" section), so checks never leak into other panels.
$cardsBlock = substr($content, strpos($content, '═══ STATISTICS CARDS ═══'), strpos($content, '═══ MY STUDENTS ═══') - strpos($content, '═══ STATISTICS CARDS ═══'));

echo "== Scenario 1: every card count is scoped to the supervisor and academic year\n";

// 1. My Students
check(strpos($content, "SELECT COUNT(*) FROM users u JOIN student_profiles sp ON sp.user_id = u.id WHERE u.role = 'student' AND u.status = 'Active' AND sp.supervisor_id = ?\" . \$ay_sql") !== false,
    'My Students counts active students assigned to supervisor with year filter');

// 2. Companies
check(strpos($content, "SELECT COUNT(DISTINCT sp.company_name) FROM users u JOIN student_profiles sp ON sp.user_id = u.id WHERE u.role = 'student' AND u.status = 'Active' AND sp.supervisor_id = ? AND sp.company_name IS NOT NULL AND sp.company_name != ''\" . \$ay_sql") !== false,
    'Companies counts DISTINCT company_name for active assigned students with year filter');

// 3. Total Reports
check(strpos($squished, "SELECT COUNT(*) FROM report_evaluations re JOIN users u ON u.id = re.student_id JOIN student_profiles sp ON sp.user_id = u.id WHERE u.role = 'student' AND sp.supervisor_id = ?\" . \$ay_sql") !== false,
    'Total Reports counts report_evaluations rows of assigned students with year filter');

// 4. Pending Reviews
$pendingBlock = substr($content, strpos($content, 'Pending reviews count'), 900);
check(strpos($pendingBlock, "report_status = 'approved_by_instructor'") !== false,
    'Pending Reviews counts only instructor-approved reports');
check(strpos($pendingBlock, 'NOT EXISTS (') !== false && strpos($pendingBlock, 'supervisor_weekly_evaluations swe') !== false,
    'Pending Reviews excludes reports already graded in supervisor_weekly_evaluations');
check(strpos($pendingBlock, "sp.supervisor_id = ?\" . \$ay_sql") !== false,
    'Pending Reviews subquery is scoped to supervisor AND academic year');

// 5. All counts are cast to int (zero-state renders as 0).
foreach (['$total_assigned', '$company_count', '$total_reports', '$pending_reviews'] as $var) {
    check((bool) preg_match('/' . preg_quote($var, '/') . ' = \(int\) (?:[^;]+fetchColumn\(\)| \$tr->fetchColumn\(\))/', $content),
        "{$var} is cast to int (zero-state safe)");
}

echo "\n== Scenario 2: every card is a clickable link to the correct route\n";

check(strpos($content, 'href="my-students.php"') !== false, 'My Students card links to my-students.php');
check(strpos($content, 'href="supervisor-reports.php"') !== false, 'Total Reports card links to supervisor-reports.php');
check(strpos($content, 'href="supervisor-companies.php"') !== false, 'Companies card links to supervisor-companies.php');
check(strpos($content, 'href="supervisor-reports.php?status=approved_by_instructor"') !== false,
    'Pending Reviews card links to the pending (awaiting grade) filter');

check(strpos($content, 'href="#my-students"') === false, 'no card still links to the in-page #my-students anchor');

// Each link must be a proper <a> card with the hover affordance.
check(substr_count($content, 'class="group bg-white rounded-2xl border border-slate-200/60 shadow-sm p-5 hover:shadow-md transition-shadow duration-300 block"') === 4,
    'all 4 cards share the clickable hover/group styling');

// Distinct badges give a clear "clickable" cue on each card.
foreach (['View All →', 'View Reports →', 'View Companies →', 'Review Reports →'] as $badge) {
    check(strpos($content, $badge) !== false, "badge present: {$badge}");
}

// Responsive grid: full-width cards on phones so the badge never overflows.
check(strpos($content, 'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5') !== false,
    'stats grid is responsive (1-up phone / 2-up tablet / 4-up desktop)');

echo "\n== Scenario 3: Pending Reviews wording (no stale copy)\n";

check(strpos($cardsBlock, 'Reports awaiting your review') !== false, 'Pending Reviews subtitle reads "Reports awaiting your review"');
check(strpos($cardsBlock, 'Needs attention') === false, 'no stale "Needs attention" copy in the cards block');
check(strpos($cardsBlock, 'All caught up') === false, 'no stale "All caught up" copy in the cards block');

echo "\n-----------------------------------------------\n";
if ($GLOBALS['failures'] === 0) {
    echo "ALL {$GLOBALS['assertions']} ASSERTIONS PASSED\n";
    exit(0);
} else {
    echo "{$GLOBALS['failures']} of {$GLOBALS['assertions']} assertions FAILED\n";
    exit(1);
}
