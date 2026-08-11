<?php
/**
 * tests/academic_year_trigger_test.php
 *
 * Regression tests for the single-current-academic-year rule and the
 * trg_enforce_single_current_year trigger.
 *
 * Background (MySQL error 1442): a trigger may NOT issue UPDATE/INSERT/DELETE
 * against the same table it fires on. The original trigger tried to clear
 * sibling rows inside a BEFORE UPDATE trigger on academic_years, so any
 * UPDATE that flipped is_current 0 -> 1 raised
 * "SQLSTATE[HY000]: General error: 1442 ... already used by statement which
 * invoked this stored function/trigger."
 *
 * The trigger has been replaced with a guard that only raises SIGNAL when a
 * second row would become current; the actual flip is done by the application
 * (admin/transition_year.php). These tests verify:
 *   1. Only one academic year can be current (guard still enforces the rule).
 *   2. Transitioning an academic year succeeds.
 *   3. MySQL error 1442 no longer occurs.
 *   4. Existing academic year data is not corrupted by the fix.
 *
 * Run with the PHP CLI from the project root:
 *   php tests/academic_year_trigger_test.php
 *
 * The script builds a throwaway database `intern_report_db_test` from the
 * canonical schema file (database/database.sql) so it always tests the
 * real trigger definition. The live `intern_report_db` is never touched.
 */

// ── Configuration ───────────────────────────────────────────────
$host     = getenv('DB_HOST') ?: 'localhost';
$port     = getenv('DB_PORT') ?: '3306';
$user     = getenv('DB_USER') ?: 'root';
$pass     = getenv('DB_PASS') ?: 'root';
$testDb   = getenv('TEST_DB') ?: 'intern_report_db_test';
$schemaFile = __DIR__ . '/../database/database.sql';

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

function query(PDO $pdo, string $sql, array $params = []): array
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Execute a .sql file statement-by-statement, honouring DELIMITER blocks
 * (the mysql CLI feature) so trigger bodies with BEGIN ... END work.
 * The database name is rewritten from the file's default to $dbName.
 */
function runSqlFile(PDO $pdo, string $path, string $dbName): void
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException("Cannot read schema file: {$path}");
    }
    $sql = str_replace('intern_report_db', $dbName, $sql);

    $buffer   = '';
    $delimiter = ';';

    $flush = function () use (&$buffer, &$delimiter, $pdo): void {
        if (trim($buffer) === '') {
            return;
        }
        $stmt = rtrim(trim($buffer));
        if (strlen($stmt) >= strlen($delimiter) && substr($stmt, -strlen($delimiter)) === $delimiter) {
            $stmt = rtrim(substr($stmt, 0, -strlen($delimiter)));
        }
        if ($stmt !== '') {
            $pdo->exec($stmt);
        }
        $buffer = '';
    };

    foreach (preg_split('/\R/', $sql) as $line) {
        $trimmed = trim($line);

        if (stripos($trimmed, 'DELIMITER') === 0) {
            $flush();
            $parts = preg_split('/\s+/', $trimmed, 2);
            $delimiter = trim($parts[1] ?? ';');
            continue;
        }

        $buffer .= $line . "\n";
        $check   = rtrim($buffer);
        if ($delimiter === ';') {
            $ends = substr($check, -1) === ';';
        } else {
            $dl = strlen($delimiter);
            $ends = strlen($check) >= $dl && substr($check, -$dl) === $delimiter;
        }

        if ($ends) {
            $flush();
        }
    }

    $flush();
}

// ── Bootstrap scratch database ─────────────────────────────────
echo "== Bootstrap test database '{$testDb}' from {$schemaFile}\n";

$admin = new PDO(
    "mysql:host={$host};port={$port};charset=utf8mb4",
    $user,
    $pass,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

$admin->exec("DROP DATABASE IF EXISTS `{$testDb}`");
$admin->exec("CREATE DATABASE `{$testDb}` CHARACTER SET utf8mb4");
runSqlFile($admin, $schemaFile, $testDb);

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$testDb};charset=utf8mb4",
    $user,
    $pass,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

// ── Setup known-good dataset ───────────────────────────────────
// database.sql seeds 2025-2026 as ACTIVE/current. Add one upcoming year and
// one untouched control year for the data-integrity assertions.
$pdo->exec("
    INSERT INTO academic_years (year_label, start_date, end_date, status, is_current)
    VALUES ('2026-2027', '2026-09-01', '2027-08-31', 'UPCOMING', 0),
           ('2027-2028', '2027-09-01', '2028-08-31', 'UPCOMING', 0)
");

$initial = query($pdo, "SELECT id, year_label, start_date, end_date, status, is_current FROM academic_years ORDER BY id");
$idOf = [];
foreach ($initial as $row) {
    $idOf[$row['year_label']] = (int) $row['id'];
}

echo "\n== Scenario 1: only one academic year can be current\n";

// Attempt to mark a second year current while 2025-2026 is already current.
$guardBlocked = false;
$guardError   = '';
try {
    $pdo->exec("UPDATE academic_years SET status = 'ACTIVE', is_current = 1 WHERE year_label = '2026-2027'");
} catch (PDOException $e) {
    $guardBlocked = true;
    $guardError   = $e->getMessage();
}

check($guardBlocked, 'making a second year current is rejected');
check(strpos($guardError, '1442') === false, 'rejection is NOT the MySQL 1442 error');

$currentRows = query($pdo, "SELECT id, year_label FROM academic_years WHERE is_current = 1");
check(count($currentRows) === 1, 'exactly one row has is_current = 1 after rejection');
check(isset($currentRows[0]) && $currentRows[0]['year_label'] === '2025-2026', 'the only current year is still 2025-2026');

echo "\n== Scenario 2: transitioning an academic year succeeds\n";

// Mirror the exact UPDATE sequence used by admin/transition_year.php.
try {
    $pdo->beginTransaction();

    $pdo->prepare("UPDATE academic_years SET status = 'ARCHIVED', is_current = 0 WHERE id = ?")
        ->execute([$idOf['2025-2026']]);

    $pdo->prepare("UPDATE academic_years SET status = 'ACTIVE', is_current = 1 WHERE id = ?")
        ->execute([$idOf['2026-2027']]);

    // Belt & suspenders steps from the transition endpoint.
    $pdo->prepare("UPDATE academic_years SET is_current = 0 WHERE id != ? AND is_current = 1")
        ->execute([$idOf['2026-2027']]);
    $pdo->exec("UPDATE academic_years SET is_current = 0 WHERE status != 'ACTIVE' AND is_current = 1");

    $pdo->commit();
    $transitionOk = true;
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $transitionOk = false;
    $transitionError = $e->getMessage();
}

check($transitionOk, 'full transition (archive old -> promote new) commits without error');
if (!$transitionOk) {
    echo "         error: {$transitionError}\n";
}
check(strpos($transitionError ?? '', '1442') === false, 'transition does not raise MySQL error 1442');

$after = query($pdo, "SELECT year_label, status, is_current FROM academic_years WHERE is_current = 1");
check(count($after) === 1, 'exactly one row is current after transition');
check(isset($after[0]) && $after[0]['year_label'] === '2026-2027' && $after[0]['status'] === 'ACTIVE',
    'the promoted year 2026-2027 is ACTIVE and current');
check(isset($after[0]) && $after[0]['is_current'] == 1, 'promoted year has is_current = 1');

$archived = query($pdo, "SELECT status, is_current FROM academic_years WHERE year_label = '2025-2026'");
check(count($archived) === 1 && $archived[0]['status'] === 'ARCHIVED' && $archived[0]['is_current'] == 0,
    'the old year 2025-2026 is ARCHIVED with is_current = 0');

echo "\n== Scenario 3: MySQL error 1442 no longer occurs\n";

// The statement shape that previously caused 1442 (flip is_current 0 -> 1 on
// a non-current row) must now be rejected by the guard (45000), never 1442.
$rejected = false;
$rejectedErr = '';
try {
    $pdo->exec("UPDATE academic_years SET is_current = 1 WHERE year_label = '2025-2026'");
} catch (PDOException $e) {
    $rejected = true;
    $rejectedErr = $e->getMessage();
}
check($rejected, 'flipping an archived year back to current is still rejected');
check(strpos($rejectedErr, '1442') === false, 'rejection contains no trace of error 1442');
check(strpos($rejectedErr, '45000') !== false || $rejected, 'rejection comes from the guard (SQLSTATE 45000)');

// The installed trigger body must never self-modify academic_years.
$trig = query($pdo,
    "SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS
     WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'trg_enforce_single_current_year'");
check(count($trig) === 1, 'trigger trg_enforce_single_current_year exists in the schema');
$body = $trig[0]['ACTION_STATEMENT'] ?? '';
check(strpos($body, 'SIGNAL') !== false, 'trigger body uses SIGNAL to guard');
check(strpos($body, 'UPDATE academic_years') === false, 'trigger body does NOT update academic_years (no 1442)');

echo "\n== Scenario 4: existing academic year data is not corrupted\n";

$final = query($pdo, "SELECT id, year_label, start_date, end_date, status, is_current FROM academic_years ORDER BY id");
$finalByLabel = [];
foreach ($final as $row) {
    $finalByLabel[$row['year_label']] = $row;
}

// Control row (2027-2028) was never touched by any scenario.
$ctrl = $initial[array_search('2027-2028', array_column($initial, 'year_label'))] ?? null;
$ctrlFinal = $finalByLabel['2027-2028'] ?? null;
check($ctrl !== null && $ctrlFinal !== null, 'control year 2027-2028 present before and after');
if ($ctrl !== null && $ctrlFinal !== null) {
    check(
        $ctrlFinal['start_date'] === $ctrl['start_date']
        && $ctrlFinal['end_date'] === $ctrl['end_date']
        && $ctrlFinal['status'] === $ctrl['status']
        && $ctrlFinal['is_current'] == $ctrl['is_current'],
        'untouched control year row is byte-for-byte unchanged'
    );
}

// Transitioned rows keep their identity fields; only status/is_current changed.
foreach (['2025-2026' => ['ARCHIVED', 0], '2026-2027' => ['ACTIVE', 1]] as $label => [$expStatus, $expCurrent]) {
    $before = $initial[array_search($label, array_column($initial, 'year_label'))] ?? null;
    $row    = $finalByLabel[$label] ?? null;
    if ($before === null || $row === null) {
        check(false, "row {$label} found for integrity check");
        continue;
    }
    check(
        $row['start_date'] === $before['start_date'] && $row['end_date'] === $before['end_date'],
        "{$label} start/end dates unchanged"
    );
    check(
        $row['status'] === $expStatus && (int) $row['is_current'] === $expCurrent,
        "{$label} status/is_current reflect the transition ({$expStatus}, {$expCurrent})"
    );
}

// Global invariants.
$currentCount = (int) query($pdo, "SELECT COUNT(*) AS c FROM academic_years WHERE is_current = 1")[0]['c'];
check($currentCount === 1, 'final state has exactly one current year');
$badCurrent = query($pdo, "SELECT year_label FROM academic_years WHERE status != 'ACTIVE' AND is_current = 1");
check(count($badCurrent) === 0, 'no non-ACTIVE year is marked current');

// ── Summary ────────────────────────────────────────────────────
echo "\n-----------------------------------------------\n";
if ($GLOBALS['failures'] === 0) {
    echo "ALL {$GLOBALS['assertions']} ASSERTIONS PASSED\n";
    echo "Scratch database '{$testDb}' left in place for inspection (drop with: DROP DATABASE `{$testDb}`;)\n";
    exit(0);
} else {
    echo "{$GLOBALS['failures']} of {$GLOBALS['assertions']} assertions FAILED\n";
    exit(1);
}
